<?php

/**
 * Part of CWM Scripture Library
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 */

namespace CWM\Library\Scripture\Bible\Provider;

use CWM\Library\Scripture\Bible\AbstractBibleProvider;
use CWM\Library\Scripture\Bible\BiblePassageResult;
use CWM\Library\Scripture\Book\BookCodes;
use CWM\Library\Scripture\Helper\ScriptureHelper;
use CWM\Library\Scripture\Helper\ScriptureReference;
use Joomla\CMS\Log\Log;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * GetBible.net API provider.
 *
 * Calls the GetBible.net v2 API to retrieve scripture passages.
 * Results are cached in #__bsms_scripture_cache.
 *
 * API endpoints:
 * - https://query.getbible.net/v2/{translation}/{reference} for verses within one chapter
 * - https://api.getbible.net/v2/{translation}/{book}/{chapter}.json for chapter-shaped
 *   references; see {@see self::isChapterShaped()}
 *
 * @since  1.0.0
 */
class GetBibleProvider extends AbstractBibleProvider
{
    /**
     * API base URL.
     *
     * @var  string
     * @since  1.0.0
     */
    private const API_BASE = 'https://query.getbible.net/v2/';

    /**
     * Chapter endpoint base URL.
     *
     * The query endpoint cannot serve chapter-shaped references. It answers
     * "Psalms 23" with verse 1 alone, and it rejects "John 3:16-4:2" as an
     * invalid reference. The chapter endpoint returns every verse of one chapter.
     *
     * @var  string
     * @since  1.1.26
     */
    private const CHAPTER_API_BASE = 'https://api.getbible.net/v2/';

    /**
     * Most chapters one passage may fetch.
     *
     * Each chapter is its own synchronous request, made while a page renders,
     * with httpGet()'s retry and backoff behind it. The longest reading in CWM
     * LivingWord's plans spans 24 chapters. A longer span is refused and logged,
     * so it can't stall the page.
     *
     * @var  int
     * @since  1.1.26
     */
    private const MAX_CHAPTERS = 30;

    /**
     * Cache tag for passages assembled from the chapter endpoint.
     *
     * Deliberately not 'getbible'. Before chapter-shaped references were routed
     * to the chapter endpoint, the query endpoint's one-verse answer for
     * "Psalms 23" was cached under 'getbible', with the same reference key both
     * entry points use. A separate tag means those truncated entries are never
     * read again, and they expire on their own.
     *
     * @var  string
     * @since  1.1.26
     */
    private const CHAPTER_CACHE = 'getbible-chapter';

    /**
     * Replace a reference's book name with the English one the API expects.
     *
     * ⚠️ Unlike the other providers, GetBible takes a book *name* on the wire
     * ("Luke 7:36-38"), and the reference arrives carrying whatever name the
     * site displays. A Czech site sent "Žalm 23:1", which the API does not
     * recognise, so passages came back empty on all thirteen translated
     * languages (#1688).
     *
     * Anything the library cannot identify is passed through untouched, so a
     * name this build has never heard of still reaches the API as typed.
     *
     * @param   string  $reference  Reference with the site's own book name
     *
     * @return  string  The same reference with an English book name
     *
     * @since   1.1.11
     */
    protected static function anglicizeBookName(string $reference): string
    {
        if (!preg_match('/^\s*(.+?)\s+(\d.*)$/', $reference, $m)) {
            return $reference;
        }

        $standard = AbstractBibleProvider::proclaimToStandard(ScriptureHelper::getBookNumber($m[1]));
        $english  = AbstractBibleProvider::getBookName($standard);

        return $english === '' ? $reference : $english . ' ' . $m[2];
    }

    /**
     * Fetch a passage from a human-readable reference.
     *
     * A reference parseReference() can read, and that is chapter-shaped, takes
     * the structured path, which fetches whole chapters. That covers a whole
     * chapter, a chapter range, or a verse range crossing chapters. Every other
     * reference takes the original query-endpoint path unchanged. That includes
     * anything parseReference() rejects, such as the verse-less chapter range
     * "John 3-4", whose meaning is still an open decision; see
     * ReferenceParseCompatibilityTest::testChapterRangeIsStillUnsupported().
     *
     * The routing happens before the cache read on purpose. The old path cached
     * verse 1 alone for a chapter under this very key.
     *
     * @param   string  $reference    Human-readable reference, e.g. "Luke 7:36-38"
     * @param   string  $translation  Translation abbreviation
     *
     * @return  BiblePassageResult
     *
     * @since   1.0.0
     */
    public function getPassage(string $reference, string $translation): BiblePassageResult
    {
        $ref = ScriptureHelper::parseReference($reference);

        if ($ref !== null && self::isChapterShaped($ref)) {
            $result = $this->getPassageFor($ref, $translation);

            // Answer with the caller's reference, as this entry point always has,
            // rather than the formatted one the structured path keys its cache on.
            return new BiblePassageResult(
                text: $result->text,
                reference: $reference,
                translation: $result->translation,
                copyright: $result->copyright,
                isHtml: $result->isHtml
            );
        }

        $cached = $this->readCache('getbible', $translation, $reference);

        if ($cached) {
            return $cached;
        }

        $apiRef = self::anglicizeBookName(str_replace('+', ' ', $reference));

        // Fix same-chapter redundant prefix: "Luke 12:54-12:56" -> "Luke 12:54-56"
        $apiRef = preg_replace_callback(
            '/(\d+):(\d+)-(\d+):(\d+)$/',
            static function (array $m): string {
                if ($m[1] === $m[3]) {
                    return $m[1] . ':' . $m[2] . '-' . $m[4];
                }

                return $m[0];
            },
            $apiRef
        );

        return $this->fetchByApiRef($apiRef, $reference, $translation);
    }

    /**
     * Fetch a passage from an already-structured reference.
     *
     * GetBible takes a book *name* on the wire, so the base class's round trip
     * hurts twice here: a caller renders the number to a localised name, and
     * anglicizeBookName() then parses that back to a number to recover the
     * English one. With the number in hand both halves collapse into a single
     * table lookup.
     *
     * @param   ScriptureReference  $ref          Parsed reference
     * @param   string              $translation  Translation abbreviation
     *
     * @return  BiblePassageResult
     *
     * @since   1.1.13
     */
    #[\Override]
    public function getPassageFor(ScriptureReference $ref, string $translation): BiblePassageResult
    {
        // The cache key stays the rendered reference, so entries written through
        // either entry point match.
        $reference = ScriptureHelper::formatReference(
            $ref->booknumber,
            $ref->chapterBegin,
            $ref->verseBegin,
            $ref->chapterEnd,
            $ref->verseEnd
        );

        // Before the 'getbible' cache read, which may hold the one-verse answer
        // the query endpoint used to give for a chapter.
        if (self::isChapterShaped($ref)) {
            return $this->fetchChapters($ref, $reference, $translation);
        }

        $cached = $this->readCache('getbible', $translation, $reference);

        if ($cached) {
            return $cached;
        }

        $english = BookCodes::name(BookCodes::toStandard($ref->booknumber));

        if ($english === '' || $ref->chapterBegin === 0) {
            Log::add(
                'GetBible: no English name for book ' . $ref->booknumber,
                Log::WARNING,
                'cwmscripture.bible'
            );

            return new BiblePassageResult(reference: $reference, translation: $translation);
        }

        return $this->fetchByApiRef(
            self::apiRefFromParts($english, $ref),
            $reference,
            $translation
        );
    }

    /**
     * Build the reference string GetBible expects.
     *
     * Assembled from parts rather than rewritten from a string, so the
     * same-chapter tidy-up getPassage() has to apply after the fact
     * ("Luke 12:54-12:56" -> "Luke 12:54-56") never needs to happen: a range
     * within one chapter simply never grows the redundant prefix.
     *
     * @param   string              $english  English book name
     * @param   ScriptureReference  $ref      Parsed reference
     *
     * @return  string
     *
     * @since   1.1.13
     */
    private static function apiRefFromParts(string $english, ScriptureReference $ref): string
    {
        $out = $english . ' ' . $ref->chapterBegin;

        if ($ref->verseBegin === 0) {
            return $out;
        }

        $out .= ':' . $ref->verseBegin;

        $chapterEnd = $ref->chapterEnd > 0 ? $ref->chapterEnd : $ref->chapterBegin;

        if ($ref->verseEnd > 0 && ($chapterEnd > $ref->chapterBegin || $ref->verseEnd > $ref->verseBegin)) {
            $out .= '-' . ($chapterEnd > $ref->chapterBegin ? $chapterEnd . ':' : '') . $ref->verseEnd;
        }

        return $out;
    }

    /**
     * Whether a reference needs whole chapters from the chapter endpoint.
     *
     * True for a whole chapter (no start verse), a chapter range, and a verse
     * range that crosses chapters. The query endpoint gets all three wrong: it
     * answers a chapter with its first verse, and rejects a cross-chapter range
     * as an invalid reference. A verse or verse range within one chapter is the
     * only shape it serves correctly, so only that shape still goes there.
     *
     * @param   ScriptureReference  $ref  Parsed reference
     *
     * @return  bool
     *
     * @since   1.1.26
     */
    private static function isChapterShaped(ScriptureReference $ref): bool
    {
        return $ref->verseBegin === 0 || $ref->chapterEnd > $ref->chapterBegin;
    }

    /**
     * Assemble a chapter-shaped passage from the chapter endpoint.
     *
     * One request per chapter, sliced to the reference's verse bounds when it
     * has them. A start verse applies to the first chapter only, and an end
     * verse to the last. With no start verse the reference means whole
     * chapters, matching how formatReference() renders it, so any end verse is
     * ignored.
     *
     * Stops at the first chapter that fails and returns an empty result. Partial
     * text would silently misquote the passage. Carrying on would also overwrite
     * the lastErrorTransient flag that httpGet() resets on every call, which
     * callers read to decide whether to retry.
     *
     * @param   ScriptureReference  $ref          Parsed, chapter-shaped reference
     * @param   string              $reference    Formatted reference, for cache and display
     * @param   string              $translation  Translation abbreviation
     *
     * @return  BiblePassageResult
     *
     * @since   1.1.26
     */
    private function fetchChapters(ScriptureReference $ref, string $reference, string $translation): BiblePassageResult
    {
        $cached = $this->readCache(self::CHAPTER_CACHE, $translation, $reference);

        if ($cached) {
            return $cached;
        }

        // 0 for the deuterocanon, which has no standard number on GetBible's wire.
        $book  = BookCodes::toStandard($ref->booknumber);
        $first = $ref->chapterBegin;
        $last  = max($ref->chapterEnd, $first);

        if ($book === 0 || $first < 1) {
            Log::add(
                'GetBible: no standard book number for book ' . $ref->booknumber,
                Log::WARNING,
                'cwmscripture.bible'
            );

            return new BiblePassageResult(reference: $reference, translation: $translation);
        }

        $count = $last - $first + 1;

        if ($count > self::MAX_CHAPTERS) {
            Log::add(
                'GetBible: "' . $reference . '" spans more than ' . self::MAX_CHAPTERS . ' chapters; not fetched',
                Log::WARNING,
                'cwmscripture.bible'
            );

            return new BiblePassageResult(reference: $reference, translation: $translation);
        }

        $bounded = $ref->verseBegin > 0;
        $text    = '';

        // Counted rather than compared against $last. A chapter number saturated
        // at PHP_INT_MAX would make "$chapter <= $last" true forever, because the
        // increment overflows to a float that still compares equal.
        for ($offset = 0; $offset < $count; $offset++) {
            $chapter = $first + $offset;
            $verses  = $this->fetchChapterVerses($translation, $book, $chapter);

            if ($verses === null) {
                return new BiblePassageResult(reference: $reference, translation: $translation);
            }

            $from = $bounded && $chapter === $first ? $ref->verseBegin : 1;
            $to   = $bounded && $chapter === $last && $ref->verseEnd > 0 ? $ref->verseEnd : PHP_INT_MAX;

            if ($text !== '') {
                $text .= ' ';
            }

            foreach ($verses as $verse) {
                if ($verse['verse'] < $from || $verse['verse'] > $to) {
                    continue;
                }

                $text .= '<sup>' . $verse['verse'] . '</sup>'
                    . htmlspecialchars(trim($verse['text'])) . ' ';
            }
        }

        $text = trim($text);

        if ($text === '') {
            return new BiblePassageResult(reference: $reference, translation: $translation);
        }

        $this->writeCache(self::CHAPTER_CACHE, $translation, $reference, $text);

        return new BiblePassageResult(
            text: $text,
            reference: $reference,
            translation: $translation,
            isHtml: true
        );
    }

    /**
     * Fetch one chapter's verses from the chapter endpoint.
     *
     * @param   string  $translation  Translation abbreviation
     * @param   int     $book         Standard book number (1-66)
     * @param   int     $chapter      Chapter number
     *
     * @return  list<array{verse: int, text: string}>|null  Null when the chapter could not be fetched
     *
     * @since   1.1.26
     */
    private function fetchChapterVerses(string $translation, int $book, int $chapter): ?array
    {
        $url  = self::CHAPTER_API_BASE . rawurlencode($translation) . '/' . $book . '/' . $chapter . '.json';
        $body = $this->httpGet($url, 15);

        if ($body === null || self::isHtmlResponse($body)) {
            Log::add(
                'GetBible: no chapter data for book ' . $book . ' chapter ' . $chapter . ' (' . $translation . ')',
                Log::WARNING,
                'cwmscripture.bible'
            );

            return null;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (!\is_array($data) || !isset($data['verses']) || !\is_array($data['verses'])) {
            Log::add(
                'GetBible: invalid chapter JSON for book ' . $book . ' chapter ' . $chapter . ' (' . $translation . ')',
                Log::ERROR,
                'cwmscripture.bible'
            );

            return null;
        }

        $verses = [];

        foreach ($data['verses'] as $verse) {
            if (!\is_array($verse)) {
                continue;
            }

            // GetBible sends verse numbers as strings ("16"), so compare as ints.
            $verses[] = [
                'verse' => (int) ($verse['verse'] ?? 0),
                'text'  => (string) ($verse['text'] ?? ''),
            ];
        }

        return $verses;
    }

    /**
     * Request a reference from the API and turn the response into a result.
     *
     * Shared by both entry points so there is one fetch: they differ only in how
     * they arrive at the API reference string.
     *
     * @param   string  $apiRef       Reference with an English book name
     * @param   string  $reference    Human-readable reference, for cache and display
     * @param   string  $translation  Translation abbreviation
     *
     * @return  BiblePassageResult
     *
     * @since   1.1.13
     */
    private function fetchByApiRef(string $apiRef, string $reference, string $translation): BiblePassageResult
    {
        $encodedRef = str_replace('%3A', ':', rawurlencode($apiRef));
        $url        = self::API_BASE . rawurlencode($translation) . '/' . $encodedRef;
        $body       = $this->httpGet($url, 15);

        if ($body === null) {
            Log::add('GetBible: API returned no data for "' . $apiRef . '" (' . $translation . ')', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        if (self::isHtmlResponse($body)) {
            Log::add('GetBible: HTML gatekeeper response for "' . $apiRef . '" (' . $translation . ')', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (!\is_array($data) || empty($data)) {
            Log::add('GetBible: Invalid JSON response for "' . $apiRef . '" (' . $translation . ')', Log::ERROR, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $text      = '';
        $copyright = '';

        foreach ($data as $passage) {
            if (!\is_array($passage) || !isset($passage['verses'])) {
                continue;
            }

            if (!empty($passage['name'])) {
                if ($text !== '') {
                    $text .= ' ';
                }
            }

            foreach ($passage['verses'] as $verse) {
                $verseNum  = $verse['verse'] ?? '';
                $verseText = $verse['text'] ?? '';
                $text .= '<sup>' . htmlspecialchars((string) $verseNum) . '</sup>'
                    . htmlspecialchars(trim($verseText)) . ' ';
            }

            if (empty($copyright) && !empty($passage['translation_note'])) {
                $copyright = $passage['translation_note'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $this->writeCache('getbible', $translation, $reference, $text, $copyright);

        return new BiblePassageResult(
            text: $text,
            reference: $reference,
            translation: $translation,
            copyright: $copyright,
            isHtml: true
        );
    }

    /**
     * @inheritDoc
     */
    public function getAvailableTranslations(): array
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['abbreviation', 'name', 'language']))
            ->from($db->quoteName('#__bsms_bible_translations'))
            ->where($db->quoteName('source') . ' = ' . $db->quote('getbible'))
            ->order($db->quoteName('name') . ' ASC');

        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /**
     * @inheritDoc
     */
    public function returnsText(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isOfflineCapable(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'getbible';
    }
}
