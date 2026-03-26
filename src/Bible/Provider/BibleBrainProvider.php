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
use CWM\Library\Scripture\Bible\AudioPassageResult;
use CWM\Library\Scripture\Bible\AudioProviderInterface;
use CWM\Library\Scripture\Bible\BiblePassageResult;
use Joomla\CMS\Log\Log;
use Joomla\Http\HttpFactory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Bible Brain (Digital Bible Platform v4) provider.
 *
 * Retrieves scripture text and audio from Faith Comes By Hearing's
 * Bible Brain API. Supports 2,500+ languages with text, audio, and
 * verse-level timing data for synchronized playback.
 *
 * Privacy: All requests are server-side. Visitor IPs are never exposed.
 * License: Free for ministry and religious/charitable purposes.
 *
 * API docs: https://www.faithcomesbyhearing.com/bible-brain/developer-documentation
 *
 * @since  1.2.0
 */
class BibleBrainProvider extends AbstractBibleProvider implements AudioProviderInterface
{
    /**
     * API base URL for Bible Brain v4.
     *
     * @var  string
     * @since  1.2.0
     */
    private const API_BASE = 'https://4.dbt.io/api';

    /**
     * USFM book codes indexed by standard book number (1-66).
     *
     * These match the Bible Brain API's expected book identifiers.
     *
     * @var  array<int, string>
     * @since  1.2.0
     */
    private const USFM_CODES = [
        1  => 'GEN', 2 => 'EXO', 3 => 'LEV', 4 => 'NUM', 5 => 'DEU',
        6  => 'JOS', 7 => 'JDG', 8 => 'RUT', 9 => '1SA', 10 => '2SA',
        11 => '1KI', 12 => '2KI', 13 => '1CH', 14 => '2CH', 15 => 'EZR',
        16 => 'NEH', 17 => 'EST', 18 => 'JOB', 19 => 'PSA', 20 => 'PRO',
        21 => 'ECC', 22 => 'SNG', 23 => 'ISA', 24 => 'JER', 25 => 'LAM',
        26 => 'EZK', 27 => 'DAN', 28 => 'HOS', 29 => 'JOL', 30 => 'AMO',
        31 => 'OBA', 32 => 'JON', 33 => 'MIC', 34 => 'NAM', 35 => 'HAB',
        36 => 'ZEP', 37 => 'HAG', 38 => 'ZEC', 39 => 'MAL',
        40 => 'MAT', 41 => 'MRK', 42 => 'LUK', 43 => 'JHN',
        44 => 'ACT', 45 => 'ROM', 46 => '1CO', 47 => '2CO',
        48 => 'GAL', 49 => 'EPH', 50 => 'PHP', 51 => 'COL',
        52 => '1TH', 53 => '2TH', 54 => '1TI', 55 => '2TI',
        56 => 'TIT', 57 => 'PHM', 58 => 'HEB', 59 => 'JAS',
        60 => '1PE', 61 => '2PE', 62 => '1JN', 63 => '2JN',
        64 => '3JN', 65 => 'JUD', 66 => 'REV',
    ];

    /**
     * Common translation abbreviation to Bible Brain Bible ID mapping.
     *
     * Bible Brain uses compound IDs like "ENGKJV" (language + version).
     * This maps common abbreviations used by the scripture library.
     *
     * @var  array<string, string>
     * @since  1.2.0
     */
    private const TRANSLATION_MAP = [
        'kjv'  => 'ENGKJV',
        'web'  => 'ENGWEB',
        'asv'  => 'ENGASV',
        'esv'  => 'ENGESV',
        'nkjv' => 'ENGNKJ',
        'nlt'  => 'ENGNLT',
        'niv'  => 'ENGNIV',
        'nasb' => 'ENGNAS',
    ];

    /**
     * The API key for authentication.
     *
     * @var  string
     * @since  1.2.0
     */
    private string $apiKey;

    /**
     * Constructor.
     *
     * @param   string  $apiKey  Bible Brain API key
     *
     * @since  1.2.0
     */
    public function __construct(string $apiKey = '')
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getPassage(string $reference, string $translation): BiblePassageResult
    {
        if (empty($this->apiKey)) {
            Log::add('BibleBrain: No API key configured', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $cached = $this->readCache('biblebrain', $translation, $reference);

        if ($cached) {
            return $cached;
        }

        $parsed = $this->parseReference($reference);

        if ($parsed === null) {
            Log::add('BibleBrain: Failed to parse reference "' . $reference . '"', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $bibleId  = $this->resolveBibleId($translation);
        $filesetId = $this->resolveTextFileset($bibleId);

        if (empty($filesetId)) {
            Log::add(
                'BibleBrain: No text fileset for translation "' . $translation . '" (Bible ID: ' . $bibleId . ')',
                Log::WARNING,
                'cwmscripture.bible'
            );

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $url = self::API_BASE . '/bibles/filesets/' . urlencode($filesetId)
            . '/' . urlencode($parsed['book']) . '/' . $parsed['chapter']
            . '?v=' . 4 . '&key=' . urlencode($this->apiKey);

        if ($parsed['verse_start'] > 0) {
            $url .= '&verse_start=' . $parsed['verse_start'];

            if ($parsed['verse_end'] > 0) {
                $url .= '&verse_end=' . $parsed['verse_end'];
            }
        }

        $body = $this->httpGet($url, 15);

        if ($body === null) {
            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Log::add('BibleBrain: Invalid JSON response for "' . $reference . '"', Log::ERROR, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $verses = $data['data'] ?? [];

        if (empty($verses)) {
            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $text = $this->assembleVerseText($verses);
        $copyright = $this->extractCopyright($data);

        $this->writeCache('biblebrain', $translation, $reference, $text, $copyright);

        return new BiblePassageResult(
            text: $text,
            reference: $reference,
            translation: $translation,
            copyright: $copyright,
            isHtml: false
        );
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getAudio(string $book, int $chapter, string $translation): AudioPassageResult
    {
        if (empty($this->apiKey)) {
            Log::add('BibleBrain: No API key configured for audio', Log::WARNING, 'cwmscripture.bible');

            return new AudioPassageResult(
                book: $book,
                chapter: $chapter,
                translation: $translation
            );
        }

        $bibleId   = $this->resolveBibleId($translation);
        $filesetId = $this->resolveAudioFileset($bibleId);

        if (empty($filesetId)) {
            Log::add(
                'BibleBrain: No audio fileset for "' . $translation . '" (Bible ID: ' . $bibleId . ')',
                Log::WARNING,
                'cwmscripture.bible'
            );

            return new AudioPassageResult(
                book: $book,
                chapter: $chapter,
                translation: $translation
            );
        }

        $url = self::API_BASE . '/bibles/filesets/' . urlencode($filesetId)
            . '/' . urlencode($book) . '/' . $chapter
            . '?v=' . 4 . '&key=' . urlencode($this->apiKey);

        $body = $this->httpGet($url, 15);

        if ($body === null) {
            return new AudioPassageResult(
                book: $book,
                chapter: $chapter,
                translation: $translation
            );
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Log::add('BibleBrain: Invalid JSON for audio ' . $book . ' ' . $chapter, Log::ERROR, 'cwmscripture.bible');

            return new AudioPassageResult(
                book: $book,
                chapter: $chapter,
                translation: $translation
            );
        }

        $audioData = $data['data'] ?? [];
        $audioFiles = [];

        foreach ($audioData as $item) {
            $audioFiles[] = [
                'url'         => $item['path'] ?? '',
                'verse_start' => (int) ($item['verse_start'] ?? 0),
                'verse_end'   => (int) ($item['verse_end'] ?? 0),
                'duration'    => (float) ($item['duration'] ?? 0.0),
            ];
        }

        $verseTiming = $this->fetchVerseTiming($filesetId, $book, $chapter);
        $copyright   = $this->extractCopyright($data);

        return new AudioPassageResult(
            audioFiles: $audioFiles,
            book: $book,
            chapter: $chapter,
            translation: $translation,
            verseTiming: $verseTiming,
            copyright: $copyright
        );
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function supportsVerseTiming(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getAvailableAudioTranslations(): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $url  = self::API_BASE . '/bibles?v=4&media=audio&language_code=ENG&key=' . urlencode($this->apiKey);
        $body = $this->httpGet($url, 20);

        if ($body === null) {
            return [];
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $translations = [];

        foreach ($data['data'] ?? [] as $bible) {
            $translations[] = [
                'id'       => $bible['abbr'] ?? '',
                'name'     => $bible['name'] ?? '',
                'language' => $bible['language']['name'] ?? 'English',
                'type'     => str_contains($bible['abbr'] ?? '', 'DA') ? 'audio_drama' : 'audio',
            ];
        }

        return $translations;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getAvailableTranslations(): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $url  = self::API_BASE . '/bibles?v=4&media=text_plain&language_code=ENG&key=' . urlencode($this->apiKey);
        $body = $this->httpGet($url, 20);

        if ($body === null) {
            return [];
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $translations = [];

        foreach ($data['data'] ?? [] as $bible) {
            $translations[] = [
                'abbreviation' => strtolower($bible['abbr'] ?? ''),
                'name'         => $bible['name'] ?? '',
                'language'     => $bible['language']['name'] ?? 'English',
            ];
        }

        return $translations;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function returnsText(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function isOfflineCapable(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getName(): string
    {
        return 'biblebrain';
    }

    /**
     * Get the USFM book codes mapping.
     *
     * @return  array<int, string>
     *
     * @since  1.2.0
     */
    public static function getUsfmCodes(): array
    {
        return self::USFM_CODES;
    }

    /**
     * Resolve a common translation abbreviation to a Bible Brain Bible ID.
     *
     * First checks the built-in map, then queries the translations table.
     *
     * @param   string  $abbreviation  Translation abbreviation (e.g. "kjv")
     *
     * @return  string  Bible Brain Bible ID (e.g. "ENGKJV")
     *
     * @since  1.2.0
     */
    private function resolveBibleId(string $abbreviation): string
    {
        $lower = strtolower(trim($abbreviation));

        if (isset(self::TRANSLATION_MAP[$lower])) {
            return self::TRANSLATION_MAP[$lower];
        }

        // Check translations table for a biblebrain provider_id
        try {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('provider_id'))
                ->from($db->quoteName('#__bsms_bible_translations'))
                ->where($db->quoteName('abbreviation') . ' = :abbr')
                ->where($db->quoteName('source') . ' = ' . $db->quote('biblebrain'))
                ->bind(':abbr', $lower);
            $db->setQuery($query);
            $result = $db->loadResult();

            if ($result) {
                return $result;
            }
        } catch (\Throwable $e) {
            // Fall through to uppercase guess
        }

        // Last resort: uppercase the abbreviation as a guess
        return strtoupper($abbreviation);
    }

    /**
     * Resolve the text fileset ID for a Bible Brain Bible ID.
     *
     * Queries the API for available filesets and returns the first
     * plain text fileset found.
     *
     * @param   string  $bibleId  Bible Brain Bible ID (e.g. "ENGKJV")
     *
     * @return  string  Fileset ID or empty string
     *
     * @since  1.2.0
     */
    private function resolveTextFileset(string $bibleId): string
    {
        return $this->resolveFileset($bibleId, 'text_plain');
    }

    /**
     * Resolve the audio fileset ID for a Bible Brain Bible ID.
     *
     * Prefers dramatized audio ('audio_drama') over plain audio ('audio').
     *
     * @param   string  $bibleId  Bible Brain Bible ID (e.g. "ENGKJV")
     *
     * @return  string  Fileset ID or empty string
     *
     * @since  1.2.0
     */
    private function resolveAudioFileset(string $bibleId): string
    {
        // Prefer dramatized audio
        $fileset = $this->resolveFileset($bibleId, 'audio_drama');

        if (!empty($fileset)) {
            return $fileset;
        }

        return $this->resolveFileset($bibleId, 'audio');
    }

    /**
     * Resolve a fileset ID of a given type for a Bible Brain Bible ID.
     *
     * @param   string  $bibleId   Bible Brain Bible ID
     * @param   string  $mediaType Media type: 'text_plain', 'audio', 'audio_drama'
     *
     * @return  string  Fileset ID or empty string
     *
     * @since  1.2.0
     */
    private function resolveFileset(string $bibleId, string $mediaType): string
    {
        // Check cache first
        $cacheKey = 'fileset_' . $bibleId . '_' . $mediaType;
        $cached   = $this->readCache('biblebrain', $cacheKey, 'fileset');

        if ($cached && $cached->hasText()) {
            return $cached->text;
        }

        $url  = self::API_BASE . '/bibles/' . urlencode($bibleId)
            . '?v=4&key=' . urlencode($this->apiKey);
        $body = $this->httpGet($url, 15);

        if ($body === null) {
            return '';
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        $filesets = $data['data']['filesets'] ?? [];

        // The filesets structure can be nested by type
        foreach ($filesets as $bucket => $sets) {
            if (!\is_array($sets)) {
                continue;
            }

            foreach ($sets as $set) {
                if (!\is_array($set)) {
                    continue;
                }

                $type = $set['type'] ?? ($set['codec'] ?? '');
                $id   = $set['id'] ?? '';

                if ($type === $mediaType && !empty($id)) {
                    // Cache the fileset resolution for 7 days
                    $this->writeCache('biblebrain', $cacheKey, 'fileset', $id);

                    return $id;
                }
            }
        }

        return '';
    }

    /**
     * Parse a scripture reference string into components.
     *
     * Handles formats like "John+3:16-18", "Genesis 1", "Psalms 23:1-6".
     *
     * @param   string  $reference  Reference string
     *
     * @return  array{book: string, chapter: int, verse_start: int, verse_end: int}|null
     *
     * @since  1.2.0
     */
    private function parseReference(string $reference): ?array
    {
        $ref = str_replace('+', ' ', trim($reference));

        // Match "Book Chapter:VerseStart-VerseEnd" or "Book Chapter"
        if (!preg_match('/^(.+?)\s+(\d+)(?::(\d+)(?:\s*-\s*(?:(\d+):)?(\d+))?)?$/i', $ref, $m)) {
            return null;
        }

        $bookName   = trim($m[1]);
        $chapter    = (int) $m[2];
        $verseStart = !empty($m[3]) ? (int) $m[3] : 0;
        $verseEnd   = !empty($m[5]) ? (int) $m[5] : 0;

        $usfmCode = $this->resolveBookToUsfm($bookName);

        if (empty($usfmCode)) {
            return null;
        }

        return [
            'book'        => $usfmCode,
            'chapter'     => $chapter,
            'verse_start' => $verseStart,
            'verse_end'   => $verseEnd,
        ];
    }

    /**
     * Resolve a book name to its USFM code.
     *
     * @param   string  $bookName  Book name (e.g. "Genesis", "1 John", "Psalms")
     *
     * @return  string  USFM code or empty string
     *
     * @since  1.2.0
     */
    private function resolveBookToUsfm(string $bookName): string
    {
        $normalized = strtolower(trim($bookName));

        foreach (self::BOOK_NAMES as $num => $name) {
            if (strtolower($name) === $normalized) {
                return self::USFM_CODES[$num] ?? '';
            }
        }

        // Try partial match for abbreviations
        foreach (self::BOOK_NAMES as $num => $name) {
            if (str_starts_with(strtolower($name), $normalized)) {
                return self::USFM_CODES[$num] ?? '';
            }
        }

        return '';
    }

    /**
     * Assemble verse objects into a single text string.
     *
     * @param   array  $verses  Array of verse objects from the API
     *
     * @return  string  Assembled passage text
     *
     * @since  1.2.0
     */
    private function assembleVerseText(array $verses): string
    {
        $parts = [];

        foreach ($verses as $verse) {
            $verseNum  = $verse['verse_start'] ?? $verse['verse'] ?? '';
            $verseText = trim($verse['verse_text'] ?? '');

            if (!empty($verseText)) {
                $parts[] = '[' . $verseNum . '] ' . $verseText;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Fetch verse-level timing data for synchronized playback.
     *
     * @param   string  $filesetId  Audio fileset ID
     * @param   string  $book       USFM book code
     * @param   int     $chapter    Chapter number
     *
     * @return  array  Per-verse timing data
     *
     * @since  1.2.0
     */
    private function fetchVerseTiming(string $filesetId, string $book, int $chapter): array
    {
        $url = self::API_BASE . '/bibles/filesets/' . urlencode($filesetId)
            . '/' . urlencode($book) . '/' . $chapter . '/timestamps'
            . '?v=4&key=' . urlencode($this->apiKey);

        $body = $this->httpGet($url, 10);

        if ($body === null) {
            return [];
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $timing = [];

        foreach ($data['data'] ?? [] as $item) {
            $timing[] = [
                'verse'           => (int) ($item['verse_start'] ?? 0),
                'timestamp_start' => (float) ($item['timestamp'] ?? 0.0),
                'timestamp_end'   => (float) ($item['timestamp_end'] ?? 0.0),
            ];
        }

        return $timing;
    }

    /**
     * Extract copyright information from an API response.
     *
     * @param   array  $data  Decoded API response
     *
     * @return  string  Copyright text
     *
     * @since  1.2.0
     */
    private function extractCopyright(array $data): string
    {
        $meta = $data['meta'] ?? [];

        return $meta['copyright'] ?? $meta['attribution'] ?? '';
    }
}
