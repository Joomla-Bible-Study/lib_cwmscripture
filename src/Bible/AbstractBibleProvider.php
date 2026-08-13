<?php

/**
 * Part of CWM Scripture Library
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 */

namespace CWM\Library\Scripture\Bible;

use CWM\Library\Scripture\Book\BookCodes;
use CWM\Library\Scripture\Helper\ScriptureHelper;
use CWM\Library\Scripture\Helper\ScriptureReference;
use Joomla\CMS\Log\Log;
use Joomla\Http\HttpFactory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Abstract base class for Bible providers.
 *
 * Provides shared utilities: book name mapping, cache read/write, HTTP fetching.
 *
 * @since  1.0.0
 */
abstract class AbstractBibleProvider implements BibleProviderInterface
{
    /**
     * Convert Proclaim book number (101-166) to standard (1-66).
     *
     * @param   int  $proclaimBook  Proclaim book number
     *
     * @return  int  Standard book number, or 0 if invalid
     *
     * @since  1.0.0
     */
    public static function proclaimToStandard(int $proclaimBook): int
    {
        return BookCodes::toStandard($proclaimBook);
    }

    /**
     * Convert standard book number (1-66) to Proclaim (101-166).
     *
     * @param   int  $standardBook  Standard book number
     *
     * @return  int  Proclaim book number
     *
     * @since  1.0.0
     */
    public static function standardToProclaim(int $standardBook): int
    {
        return BookCodes::toProclaim($standardBook);
    }

    /**
     * The OSIS/USFM code for a standard book number.
     *
     * @param   int  $standardBook  Standard book number (1-66)
     *
     * @return  string  Book code, or an empty string when out of range
     *
     * @since  1.1.11
     */
    public static function bookCode(int $standardBook): string
    {
        return BookCodes::code($standardBook);
    }

    /**
     * The OSIS/USFM code for a Proclaim book number (101-173).
     *
     * @param   int  $proclaimBook  Proclaim book number
     *
     * @return  string  Book code, or an empty string when there is no standard equivalent
     *
     * @since  1.1.11
     */
    public static function bookCodeForProclaim(int $proclaimBook): string
    {
        return BookCodes::forProclaim($proclaimBook);
    }

    /**
     * Fetch a passage from an already-structured reference.
     *
     * The structured entry point. `getPassage()` takes a string, so a caller
     * holding a parsed reference had to render it to a name and hand that over,
     * and the provider then recovered the book from that name by string
     * matching — a round trip through the least reliable representation
     * available, and the reason scripture broke on translated sites (#1688).
     *
     * This default preserves that behaviour exactly, so a provider that has not
     * been converted is unaffected: it renders the reference and calls the
     * string method. A converted provider overrides this with the real fetch and
     * reduces its own `getPassage()` to parse-and-delegate, which is what
     * removes the round trip rather than routing around it.
     *
     * Deliberately **not** declared on `BibleProviderInterface`. Every provider
     * extends this class, so they all inherit it; adding it to the interface is
     * the one change that would break an implementor outside this repository,
     * and it buys nothing until every provider has been converted.
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

        // formatReference() returns '' for an unknown book or a missing chapter.
        // Passing that on would have the provider fail against an empty string
        // and report it as the reference, so answer with what the caller gave us.
        if ($reference === '') {
            return new BiblePassageResult(
                reference: $ref->referenceText,
                translation: $translation
            );
        }

        return $this->getPassage($reference, $translation);
    }

    /**
     * Resolve a book name, or an unambiguous abbreviation of one, to its code.
     *
     * ⚠️ BOOK_NAMES is English, and callers hand this the name a site displays.
     * `ScriptureHelper::getBookName()` returns `Text::_()` of the book's key, so
     * on any of the thirteen translated languages that name arrives as "Žalm" or
     * "Juan" and matched nothing here — every remote provider then had no code
     * to send and returned an empty passage (#1688).
     *
     * ScriptureHelper is asked first: it holds the 187-entry abbreviation table
     * and a map of the *translated* names, both exact-match. Only when it has no
     * answer does the English table below run, so a site whose language pack is
     * missing behaves exactly as it did before.
     *
     * Exact match before prefix match. The prefix pass is what makes typed
     * abbreviations work, but it must only answer when exactly one book matches:
     * five books begin "Jo", and returning whichever came first in the table
     * meant "Jo" silently resolved to Joshua and never John (#1688).
     *
     * @param   string  $bookName  A book name or abbreviation, in any language
     *
     * @return  string  Book code, or an empty string when unknown or ambiguous
     *
     * @since  1.1.11
     */
    public static function resolveBookCode(string $bookName): string
    {
        $normalized = strtolower(trim($bookName));

        if ($normalized === '') {
            return '';
        }

        $proclaimBook = ScriptureHelper::getBookNumber($bookName);

        if ($proclaimBook > 0) {
            $code = self::bookCodeForProclaim($proclaimBook);

            if ($code !== '') {
                return $code;
            }
        }

        foreach (BookCodes::names() as $num => $name) {
            if (strtolower($name) === $normalized) {
                return self::bookCode($num);
            }
        }

        $matches = [];

        foreach (BookCodes::names() as $num => $name) {
            if (str_starts_with(strtolower($name), $normalized)) {
                $matches[] = $num;
            }
        }

        return \count($matches) === 1 ? self::bookCode($matches[0]) : '';
    }

    /**
     * Get a book name by standard book number.
     *
     * @param   int  $bookNumber  Standard book number (1-66)
     *
     * @return  string  Book name or empty string
     *
     * @since  1.0.0
     */
    public static function getBookName(int $bookNumber): string
    {
        return BookCodes::name($bookNumber);
    }

    /**
     * Every English book name, keyed by standard book number.
     *
     * `getBookName()` answers for one book, and until `BookCodes` existed
     * nothing returned the table. A consumer that wanted to scan it had only
     * one option — reflection against `BOOK_NAMES`, then a `protected` constant
     * — and CWMLivingWord took it, which is how moving the constant into
     * `BookCodes` broke a consumer with no deprecation signal (CWMLivingWord#118).
     *
     * `BookCodes::names()` is the canonical accessor and new code should call it
     * directly. This delegate exists so the whole book API stays reachable from
     * one class, matching `bookCode()`, `getBookName()` and the rest.
     *
     * ⚠️ English. To resolve a name a site displays, use `resolveBookCode()`,
     * which consults the translated names and abbreviations first (#1688).
     *
     * @return  array<int, string>
     *
     * @since  1.1.13
     */
    public static function bookNames(): array
    {
        return BookCodes::names();
    }

    /**
     * Read a cached passage from the scripture_cache table.
     *
     * @param   string  $provider     Provider name
     * @param   string  $translation  Translation abbreviation
     * @param   string  $reference    Reference string
     *
     * @return  BiblePassageResult|null  Cached result or null if not found/expired
     *
     * @since  1.0.0
     */
    protected function readCache(string $provider, string $translation, string $reference): ?BiblePassageResult
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['text', 'copyright']))
            ->from($db->quoteName('#__bsms_scripture_cache'))
            ->where($db->quoteName('provider') . ' = :provider')
            ->where($db->quoteName('translation') . ' = :translation')
            ->where($db->quoteName('reference') . ' = :reference')
            ->where($db->quoteName('expires_at') . ' > NOW()')
            ->bind(':provider', $provider)
            ->bind(':translation', $translation)
            ->bind(':reference', $reference);

        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            return null;
        }

        return new BiblePassageResult(
            text: $row->text,
            reference: $reference,
            translation: $translation,
            copyright: $row->copyright ?? '',
            isHtml: false
        );
    }

    /**
     * Write a passage to the scripture_cache table.
     *
     * @param   string  $provider     Provider name
     * @param   string  $translation  Translation abbreviation
     * @param   string  $reference    Reference string
     * @param   string  $text         The passage text
     * @param   string  $copyright    Copyright notice
     *
     * @return  void
     *
     * @since  1.0.0
     */
    protected function writeCache(
        string $provider,
        string $translation,
        string $reference,
        string $text,
        string $copyright = ''
    ): void {
        $db        = $this->getDatabase();
        $expiresAt = date('Y-m-d H:i:s', time() + $this->cacheTtl);

        // Upsert: delete old entry then insert new
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__bsms_scripture_cache'))
            ->where($db->quoteName('provider') . ' = :provider')
            ->where($db->quoteName('translation') . ' = :translation')
            ->where($db->quoteName('reference') . ' = :reference')
            ->bind(':provider', $provider)
            ->bind(':translation', $translation)
            ->bind(':reference', $reference);
        $db->setQuery($query);
        $db->execute();

        $columns = ['provider', 'translation', 'reference', 'text', 'copyright', 'expires_at'];
        $values  = ':provider2, :translation2, :reference2, :text, :copyright, :expires_at';

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__bsms_scripture_cache'))
            ->columns($db->quoteName($columns))
            ->values($values)
            ->bind(':provider2', $provider)
            ->bind(':translation2', $translation)
            ->bind(':reference2', $reference)
            ->bind(':text', $text)
            ->bind(':copyright', $copyright)
            ->bind(':expires_at', $expiresAt);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Detect HTML responses (DDoS gatekeeper pages).
     *
     * @param   string  $body  Response body to check
     *
     * @return  bool  True if the body appears to be HTML
     *
     * @since  1.0.0
     */
    protected static function isHtmlResponse(string $body): bool
    {
        $trimmed = ltrim($body);

        return str_starts_with($trimmed, '<!') || str_starts_with(strtolower($trimmed), '<html');
    }

    /**
     * Perform an HTTP GET request with retry and backoff.
     *
     * Retries on transient errors (429, 503, timeouts, HTML gatekeeper pages).
     * Non-retryable errors (400, 404) fail immediately.
     *
     * @param   string  $url      The URL to fetch
     * @param   int     $timeout  Timeout in seconds
     *
     * @return  string|null  Response body or null on failure
     *
     * @since  1.0.0
     */
    protected function httpGet(string $url, int $timeout = 10): ?string
    {
        $this->lastErrorTransient = false;
        $factory                  = new HttpFactory();
        $http                     = $factory->getHttp();
        $host                     = strtok($url, '?');

        for ($attempt = 1; $attempt <= self::HTTP_MAX_RETRIES; $attempt++) {
            if ($attempt > 1) {
                $delay = (int) pow(2, $attempt - 1);
                Log::add(
                    'Retry ' . ($attempt - 1) . '/' . (self::HTTP_MAX_RETRIES - 1) . ' for ' . $host . ' (waiting ' . $delay . 's)',
                    Log::WARNING,
                    'cwmscripture.bible'
                );
                sleep($delay);
            }

            try {
                $response = $http->get($url, [], $timeout);
            } catch (\Exception $e) {
                Log::add('HTTP request failed (attempt ' . $attempt . '): ' . $e->getMessage(), Log::WARNING, 'cwmscripture.bible');
                $this->lastErrorTransient = true;

                continue;
            }

            $code = $response->getStatusCode();
            $body = (string) $response->getBody();

            if (\in_array($code, [400, 404], true)) {
                Log::add('HTTP ' . $code . ' from ' . $host . ' (not retryable)', Log::WARNING, 'cwmscripture.bible');
                $this->lastErrorTransient = false;

                return null;
            }

            if (\in_array($code, [429, 503], true)) {
                Log::add('HTTP ' . $code . ' from ' . $host . ' (attempt ' . $attempt . ')', Log::WARNING, 'cwmscripture.bible');
                $this->lastErrorTransient = true;

                continue;
            }

            if ($code === 200) {
                if (self::isHtmlResponse($body)) {
                    Log::add('HTML gatekeeper detected from ' . $host . ' (attempt ' . $attempt . ')', Log::WARNING, 'cwmscripture.bible');
                    $this->lastErrorTransient = true;

                    continue;
                }

                return $body;
            }

            Log::add('HTTP ' . $code . ' from ' . $host . ' (attempt ' . $attempt . ')', Log::WARNING, 'cwmscripture.bible');
            $this->lastErrorTransient = true;
        }

        Log::add('All ' . self::HTTP_MAX_RETRIES . ' attempts exhausted for ' . $host, Log::ERROR, 'cwmscripture.bible');

        return null;
    }
}
