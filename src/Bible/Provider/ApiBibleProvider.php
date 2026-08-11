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
        return self::BOOK_CODES;
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

        $passageId = $osisCode . '.' . $chapter . '.' . $verseStart;

        if ($verseEnd !== null && ($chapterEnd > $chapter || $verseEnd > $verseStart)) {
            $passageId .= '-' . $osisCode . '.' . $chapterEnd . '.' . $verseEnd;
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
