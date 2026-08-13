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
 * API endpoint: https://query.getbible.net/v2/{translation}/{reference}
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
     * @inheritDoc
     */
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

    public function getPassage(string $reference, string $translation): BiblePassageResult
    {
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
        $query = $db->getQuery(true)
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
