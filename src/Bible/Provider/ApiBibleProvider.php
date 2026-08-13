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
use Joomla\Http\HttpFactory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * API.Bible provider (American Bible Society).
 *
 * Retrieves scripture passages from the API.Bible REST API using server-side
 * HTTP requests. Provides access to copyrighted translations (NIV, ESV, NLT, etc.)
 * when the user has an approved API key.
 *
 * Privacy: All requests are server-side. Visitor IPs are never exposed.
 * FUMS tracking is done via server-side ping (no client-side JS).
 *
 * API docs: https://docs.api.bible/
 *
 * @since  1.0.0
 */
class ApiBibleProvider extends AbstractBibleProvider
{
    /**
     * API base URL.
     *
     * @var  string
     * @since  1.0.0
     */
    private const API_BASE = 'https://rest.api.bible/v1';

    /**
     * FUMS tracking base URL for server-side pings.
     *
     * @var  string
     * @since  1.0.0
     */
    private const FUMS_BASE = 'https://fums.api.bible/f3';

    /**
     * The API key for authentication.
     *
     * @var  string
     * @since  1.0.0
     */
    private string $apiKey;

    /**
     * Constructor.
     *
     * @param   string  $apiKey  API.Bible API key
     *
     * @since  1.0.0
     */
    public function __construct(string $apiKey = '')
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @inheritDoc
     */
    public function getPassage(string $reference, string $translation): BiblePassageResult
    {
        if (empty($this->apiKey)) {
            Log::add('ApiBible: No API key configured', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $cached = $this->readCache('api_bible', $translation, $reference);

        if ($cached) {
            return $cached;
        }

        $bibleId = $this->getBibleId($translation);

        if (empty($bibleId)) {
            Log::add('ApiBible: No Bible ID for translation "' . $translation . '"', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $passageId = $this->buildPassageId($reference);

        if (empty($passageId)) {
            Log::add('ApiBible: Failed to parse reference "' . $reference . '"', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        return $this->fetchByPassageId($passageId, $bibleId, $reference, $translation);
    }

    /**
     * Fetch a passage from an already-structured reference.
     *
     * Overrides the base implementation, which renders the reference to a name
     * and hands it to getPassage() — the round trip #1688 is about. Here the
     * book code comes straight off the book number.
     *
     * The rendered reference is still used as the cache key and display
     * fallback, so entries written through either entry point match.
     *
     * @param   ScriptureReference  $ref          Parsed reference
     * @param   string              $translation  Translation abbreviation
     *
     * @return  BiblePassageResult
     *
     * @since   1.1.13
     */
    public function getPassageFor(ScriptureReference $ref, string $translation): BiblePassageResult
    {
        $reference = ScriptureHelper::formatReference(
            $ref->booknumber,
            $ref->chapterBegin,
            $ref->verseBegin,
            $ref->chapterEnd,
            $ref->verseEnd
        );

        if (empty($this->apiKey)) {
            Log::add('ApiBible: No API key configured', Log::WARNING, 'cwmscripture.bible');

            return new BiblePassageResult(reference: $reference, translation: $translation);
        }

        $cached = $this->readCache('api_bible', $translation, $reference);

        if ($cached) {
            return $cached;
        }

        $bibleId = $this->getBibleId($translation);

        if (empty($bibleId)) {
            Log::add(
                'ApiBible: No Bible ID for translation "' . $translation . '"',
                Log::WARNING,
                'cwmscripture.bible'
            );

            return new BiblePassageResult(reference: $reference, translation: $translation);
        }

        $passageId = $this->buildPassageIdFor($ref);

        if (empty($passageId)) {
            Log::add(
                'ApiBible: Could not build a passage id for book ' . $ref->booknumber,
                Log::WARNING,
                'cwmscripture.bible'
            );

            return new BiblePassageResult(reference: $reference, translation: $translation);
        }

        return $this->fetchByPassageId($passageId, $bibleId, $reference, $translation);
    }

    /**
     * Request a passage id and turn the response into a result.
     *
     * Shared by both entry points so there is one fetch: they differ only in how
     * they arrive at the passage id, not in what they do with it.
     *
     * @param   string  $passageId    OSIS/USFM passage id, e.g. `JHN.3.16`
     * @param   string  $bibleId      api.bible Bible id
     * @param   string  $reference    Human-readable reference, used for cache and display
     * @param   string  $translation  Translation abbreviation
     *
     * @return  BiblePassageResult
     *
     * @since   1.1.13
     */
    private function fetchByPassageId(
        string $passageId,
        string $bibleId,
        string $reference,
        string $translation
    ): BiblePassageResult {
        $url  = self::API_BASE . '/bibles/' . urlencode($bibleId)
            . '/passages/' . urlencode($passageId)
            . '?content-type=text&include-verse-numbers=true';
        $body = $this->httpGetWithApiKey($url);

        if ($body === null) {
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

        if (!\is_array($data) || !isset($data['data'])) {
            Log::add('ApiBible: Invalid JSON response for "' . $reference . '"', Log::ERROR, 'cwmscripture.bible');

            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $passage   = $data['data'];
        $text      = trim($passage['content'] ?? '');
        $copyright = $passage['copyright'] ?? '';
        $humanRef  = $passage['reference'] ?? $reference;

        $fumsId = $data['meta']['fumsId'] ?? '';

        if (!empty($fumsId)) {
            $this->fireFums($fumsId);
        }

        if (empty($text)) {
            return new BiblePassageResult(
                reference: $reference,
                translation: $translation
            );
        }

        $this->writeCache('api_bible', $translation, $reference, $text, $copyright);

        return new BiblePassageResult(
            text: $text,
            reference: $humanRef,
            translation: $translation,
            copyright: $copyright,
            isHtml: false
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
            ->where($db->quoteName('source') . ' = ' . $db->quote('api_bible'))
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
        return 'api_bible';
    }

    /**
     * Get the OSIS book codes mapping.
     *
     * @return  array<int, string>
     *
     * @since  1.0.0
     */
    public static function getOsisCodes(): array
    {
        return BookCodes::codes();
    }

    /**
     * Build an OSIS-format passage ID from a reference string.
     *
     * @param   string  $reference  Reference string like "John+3:16-18"
     *
     * @return  string  OSIS passage ID or empty string on failure
     *
     * @since  1.0.0
     */
    public function buildPassageId(string $reference): string
    {
        $ref = str_replace('+', ' ', trim($reference));

        if (!preg_match('/^(.+?)\s+(\d+):(\d+)(?:\s*-\s*(?:(\d+):)?(\d+))?$/i', $ref, $m)) {
            return '';
        }

        $bookName    = trim($m[1]);
        $chapter     = (int) $m[2];
        $verseStart  = (int) $m[3];
        $chapterEnd  = !empty($m[4]) ? (int) $m[4] : $chapter;
        $verseEnd    = !empty($m[5]) ? (int) $m[5] : null;

        $osisCode = self::resolveBookCode($bookName);

        if (empty($osisCode)) {
            return '';
        }

        return self::passageIdFromParts($osisCode, $chapter, $verseStart, $chapterEnd, $verseEnd);
    }

    /**
     * Build a passage id from an already-structured reference.
     *
     * The structured counterpart to buildPassageId(). That method has to recover
     * the book from a name, which is why it keeps resolveBookCode() and its
     * single-match prefix pass — typed abbreviations like "Joh" are real input
     * there. A ScriptureReference already carries the number, so the code comes
     * straight from it and no name is involved.
     *
     * Returns '' for a reference with no verse, matching buildPassageId(), whose
     * regex requires one. api.bible can address a whole chapter, so that is a
     * capability worth adding — but as a deliberate change, not as a side effect
     * of this one.
     *
     * @param   ScriptureReference  $ref  Parsed reference
     *
     * @return  string  Passage id, or '' when the book or chapter is unknown
     *
     * @since   1.1.13
     */
    public function buildPassageIdFor(ScriptureReference $ref): string
    {
        $code = self::bookCodeForProclaim($ref->booknumber);

        if ($code === '' || $ref->chapterBegin === 0 || $ref->verseBegin === 0) {
            return '';
        }

        $chapterEnd = $ref->chapterEnd > 0 ? $ref->chapterEnd : $ref->chapterBegin;
        $verseEnd   = $ref->verseEnd > 0 ? $ref->verseEnd : null;

        return self::passageIdFromParts(
            $code,
            $ref->chapterBegin,
            $ref->verseBegin,
            $chapterEnd,
            $verseEnd
        );
    }

    /**
     * Assemble the passage id string.
     *
     * Held in one place so the two builders can differ in how they resolve the
     * book without also drifting on the format api.bible expects.
     *
     * @param   string    $code         Book code
     * @param   int       $chapter      Starting chapter
     * @param   int       $verseStart   Starting verse
     * @param   int       $chapterEnd   Ending chapter
     * @param   int|null  $verseEnd     Ending verse, or null for a single verse
     *
     * @return  string
     *
     * @since   1.1.13
     */
    private static function passageIdFromParts(
        string $code,
        int $chapter,
        int $verseStart,
        int $chapterEnd,
        ?int $verseEnd
    ): string {
        $passageId = $code . '.' . $chapter . '.' . $verseStart;

        if ($verseEnd !== null && ($chapterEnd > $chapter || $verseEnd > $verseStart)) {
            $passageId .= '-' . $code . '.' . $chapterEnd . '.' . $verseEnd;
        }

        return $passageId;
    }

    /**
     * Look up the api.bible Bible ID for a translation abbreviation.
     *
     * @param   string  $abbreviation  Translation abbreviation
     *
     * @return  string|null  The provider_id or null
     *
     * @since  1.0.0
     */
    private function getBibleId(string $abbreviation): ?string
    {
        try {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('provider_id'))
                ->from($db->quoteName('#__bsms_bible_translations'))
                ->where($db->quoteName('abbreviation') . ' = :abbr')
                ->where($db->quoteName('source') . ' = ' . $db->quote('api_bible'))
                ->bind(':abbr', $abbreviation);
            $db->setQuery($query);

            return $db->loadResult() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Perform an HTTP GET with the api-key header.
     *
     * @param   string  $url      URL to fetch
     * @param   int     $timeout  Timeout in seconds
     *
     * @return  string|null  Response body or null on failure
     *
     * @since  1.0.0
     */
    private function httpGetWithApiKey(string $url, int $timeout = 15): ?string
    {
        try {
            $factory  = new HttpFactory();
            $http     = $factory->getHttp();
            $response = $http->get($url, ['api-key' => $this->apiKey], $timeout);
            $code     = $response->getStatusCode();

            if ($code === 200) {
                return (string) $response->getBody();
            }

            Log::add('ApiBible: HTTP ' . $code . ' from ' . strtok($url, '?'), Log::ERROR, 'cwmscripture.bible');
        } catch (\Exception $e) {
            Log::add('ApiBible: HTTP error: ' . $e->getMessage(), Log::ERROR, 'cwmscripture.bible');
        }

        return null;
    }

    /**
     * Fire a FUMS tracking ping (mandatory per API.Bible ToS).
     *
     * @param   string  $fumsId  The FUMS token from the API response
     *
     * @return  void
     *
     * @since  1.0.0
     */
    private function fireFums(string $fumsId): void
    {
        try {
            $url = self::FUMS_BASE . '?t=' . urlencode($fumsId) . '&dId=cwmscripture&sId=server';
            $this->httpGet($url, 5);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }
}
