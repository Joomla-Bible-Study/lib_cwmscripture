<?php

/**
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\BiblePassageResult;
use CWM\Library\Scripture\Bible\Provider\GetBibleProvider;
use CWM\Library\Scripture\Helper\ScriptureHelper;
use CWM\Library\Scripture\Helper\ScriptureReference;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Chapter-shaped references reach GetBible's chapter endpoint (#100).
 *
 * GetBible's query endpoint answers "Psalms 23" with verse 1 alone, and rejects
 * "John 3:16-4:2" as an invalid reference. Neither failure throws: the first
 * returns a confident one-verse passage, and the second returns an empty one.
 * These tests replace HTTP and the cache with in-memory doubles. They pin which
 * endpoint each reference shape reaches and what text comes back.
 *
 * @since  __DEPLOY_VERSION__
 */
class GetBibleChapterRoutingTest extends TestCase
{
    private const CHAPTER_URL = 'https://api.getbible.net/v2/kjv/';

    private const QUERY_URL = 'https://query.getbible.net/v2/kjv/';

    /**
     * A GetBible provider whose HTTP and cache live in memory.
     *
     * @param   array<string, string>              $responses  URL => response body; anything else fails
     * @param   array<string, BiblePassageResult>  $cache      "provider|translation|reference" => cached result
     *
     * @return  GetBibleProvider
     */
    private static function provider(array $responses = [], array $cache = []): GetBibleProvider
    {
        $provider = new class () extends GetBibleProvider {
            /** @var array<string, string> */
            public array $responses = [];

            /** @var array<string, BiblePassageResult> */
            public array $cache = [];

            /** @var list<string> */
            public array $requested = [];

            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $written = [];

            protected function httpGet(string $url, int $timeout = 10): ?string
            {
                $this->requested[] = $url;

                return $this->responses[$url] ?? null;
            }

            protected function readCache(string $provider, string $translation, string $reference): ?BiblePassageResult
            {
                return $this->cache[$provider . '|' . $translation . '|' . $reference] ?? null;
            }

            protected function writeCache(
                string $provider,
                string $translation,
                string $reference,
                string $text,
                string $copyright = ''
            ): void {
                $this->written[] = [$provider, $translation, $reference];
            }
        };

        $provider->responses = $responses;
        $provider->cache     = $cache;

        return $provider;
    }

    /**
     * A chapter-endpoint response, with verse numbers as strings like GetBible sends.
     *
     * @param   int  $chapter  Chapter number
     * @param   int  $verses   How many verses the chapter has
     *
     * @return  string
     */
    private static function chapterJson(int $chapter, int $verses): string
    {
        $list = [];

        for ($verse = 1; $verse <= $verses; $verse++) {
            $list[] = [
                'chapter' => (string) $chapter,
                'verse'   => (string) $verse,
                'name'    => 'Book ' . $chapter . ':' . $verse,
                'text'    => 'c' . $chapter . 'v' . $verse,
            ];
        }

        return json_encode(['chapter' => $chapter, 'verses' => $list], JSON_THROW_ON_ERROR);
    }

    /**
     * The cache key the structured path uses for a reference.
     *
     * @param   ScriptureReference  $ref  Reference
     *
     * @return  string
     */
    private static function key(ScriptureReference $ref): string
    {
        return ScriptureHelper::formatReference(
            $ref->booknumber,
            $ref->chapterBegin,
            $ref->verseBegin,
            $ref->chapterEnd,
            $ref->verseEnd
        );
    }

    #[TestDox('⚠️ a whole chapter returns every verse, not just the first')]
    public function testWholeChapterReturnsEveryVerse(): void
    {
        $ref      = new ScriptureReference(booknumber: 119, chapterBegin: 23);
        $provider = self::provider([self::CHAPTER_URL . '19/23.json' => self::chapterJson(23, 6)]);

        $result = $provider->getPassageFor($ref, 'kjv');

        $this->assertSame([self::CHAPTER_URL . '19/23.json'], $provider->requested);
        $this->assertSame(6, substr_count($result->text, '<sup>'), 'all six verses, not verse 1 alone');
        $this->assertStringContainsString('<sup>6</sup>c23v6', $result->text);
        $this->assertSame([['getbible-chapter', 'kjv', self::key($ref)]], $provider->written);
    }

    #[TestDox('a chapter range fetches each chapter in order')]
    public function testChapterRangeFetchesEachChapter(): void
    {
        $ref      = new ScriptureReference(booknumber: 101, chapterBegin: 1, chapterEnd: 3);
        $provider = self::provider([
            self::CHAPTER_URL . '1/1.json' => self::chapterJson(1, 3),
            self::CHAPTER_URL . '1/2.json' => self::chapterJson(2, 2),
            self::CHAPTER_URL . '1/3.json' => self::chapterJson(3, 4),
        ]);

        $result = $provider->getPassageFor($ref, 'kjv');

        $this->assertSame(
            [self::CHAPTER_URL . '1/1.json', self::CHAPTER_URL . '1/2.json', self::CHAPTER_URL . '1/3.json'],
            $provider->requested
        );
        $this->assertSame(9, substr_count($result->text, '<sup>'));
        $this->assertStringStartsWith('<sup>1</sup>c1v1', $result->text);
        $this->assertStringEndsWith('<sup>4</sup>c3v4', $result->text);
    }

    #[TestDox('⚠️ a cross-chapter verse range is sliced to its bounds')]
    public function testCrossChapterRangeIsSliced(): void
    {
        // John 3:16-4:2. The query endpoint rejects this outright.
        $ref      = new ScriptureReference(booknumber: 143, chapterBegin: 3, verseBegin: 16, chapterEnd: 4, verseEnd: 2);
        $provider = self::provider([
            self::CHAPTER_URL . '43/3.json' => self::chapterJson(3, 36),
            self::CHAPTER_URL . '43/4.json' => self::chapterJson(4, 54),
        ]);

        $result = $provider->getPassageFor($ref, 'kjv');

        $this->assertStringStartsWith('<sup>16</sup>c3v16', $result->text);
        $this->assertStringEndsWith('<sup>2</sup>c4v2', $result->text);
        $this->assertStringNotContainsString('c3v15', $result->text);
        $this->assertStringNotContainsString('c4v3', $result->text);
        $this->assertSame((36 - 15) + 2, substr_count($result->text, '<sup>'));
    }

    #[TestDox('a verse range within one chapter keeps the query endpoint')]
    public function testVerseRangeKeepsTheQueryEndpoint(): void
    {
        $provider = self::provider();

        $provider->getPassageFor(
            new ScriptureReference(booknumber: 142, chapterBegin: 7, verseBegin: 36, chapterEnd: 7, verseEnd: 38),
            'kjv'
        );
        $provider->getPassage('Luke 7:36-38', 'kjv');

        $this->assertCount(2, $provider->requested);

        foreach ($provider->requested as $url) {
            $this->assertStringStartsWith(self::QUERY_URL, $url, 'the query endpoint serves this shape correctly');
        }
    }

    #[TestDox('⚠️ a stale one-verse cache entry from the old path is never served')]
    public function testStaleOneVerseEntryIsBypassed(): void
    {
        // Before the fix, the query endpoint's one-verse answer was cached under
        // 'getbible' with the same keys both entry points use. With the default
        // 30-day cache, those entries would outlive the fix unless bypassed.
        $ref   = new ScriptureReference(booknumber: 119, chapterBegin: 23);
        $stale = new BiblePassageResult(text: '<sup>1</sup>stale verse one', reference: 'stale');

        $provider = self::provider(
            [self::CHAPTER_URL . '19/23.json' => self::chapterJson(23, 6)],
            [
                'getbible|kjv|' . self::key($ref) => $stale,
                'getbible|kjv|Psalm 23'           => $stale,
            ]
        );

        $structured = $provider->getPassageFor($ref, 'kjv');
        $fromString = $provider->getPassage('Psalm 23', 'kjv');

        $this->assertStringNotContainsString('stale', $structured->text);
        $this->assertStringNotContainsString('stale', $fromString->text);
        $this->assertStringContainsString('c23v6', $fromString->text);
    }

    #[TestDox('a chapter-only string takes the chapter endpoint and keeps the caller\'s reference')]
    public function testStringChapterReferenceKeepsCallerReference(): void
    {
        // The ScriptureLinks content plugin passes {scripture}Psalm 23{/scripture}
        // to getPassage() as a string, so the string entry point needs the fix too.
        $provider = self::provider([self::CHAPTER_URL . '19/23.json' => self::chapterJson(23, 6)]);

        $result = $provider->getPassage('Psalm 23', 'kjv');

        $this->assertSame([self::CHAPTER_URL . '19/23.json'], $provider->requested);
        $this->assertSame('Psalm 23', $result->reference);
        $this->assertTrue($result->isHtml);
    }

    #[TestDox('a verse-less chapter range string keeps its original path')]
    public function testUnparseableChapterRangeKeepsOriginalPath(): void
    {
        // parseReference() rejects "John 3-4" on purpose; what it should mean is
        // an open decision (ReferenceParseCompatibilityTest). This fix must not
        // decide it, so the string goes exactly where it went before.
        $provider = self::provider();

        $provider->getPassage('John 3-4', 'kjv');

        $this->assertSame([self::QUERY_URL . 'John%203-4'], $provider->requested);
    }

    #[TestDox('⚠️ a failed chapter stops the fetch and caches nothing')]
    public function testFailedChapterStopsTheFetch(): void
    {
        // Chapter 2 has no response, so httpGet() returns null for it.
        $provider = self::provider([
            self::CHAPTER_URL . '1/1.json' => self::chapterJson(1, 3),
            self::CHAPTER_URL . '1/3.json' => self::chapterJson(3, 4),
        ]);

        $result = $provider->getPassageFor(new ScriptureReference(booknumber: 101, chapterBegin: 1, chapterEnd: 3), 'kjv');

        $this->assertSame('', $result->text, 'partial text would misquote the passage');
        $this->assertSame(
            [self::CHAPTER_URL . '1/1.json', self::CHAPTER_URL . '1/2.json'],
            $provider->requested,
            'must not request chapter 3 after chapter 2 failed'
        );
        $this->assertSame([], $provider->written);
    }

    #[TestDox('a span beyond the chapter cap is refused without a request')]
    public function testSpanBeyondTheCapIsRefused(): void
    {
        $provider = self::provider();

        $result = $provider->getPassageFor(new ScriptureReference(booknumber: 119, chapterBegin: 1, chapterEnd: 31), 'kjv');

        $this->assertSame([], $provider->requested);
        $this->assertSame('', $result->text);
    }

    #[TestDox('⚠️ chapter numbers saturated at PHP_INT_MAX cannot loop forever')]
    public function testSaturatedChapterNumbersTerminate(): void
    {
        // "$chapter <= $last" with $last at PHP_INT_MAX never goes false: the
        // increment overflows to a float that still compares equal. Both chapters
        // answer here, so only a bounded loop stops after two requests.
        $last     = PHP_INT_MAX;
        $first    = $last - 1;
        $provider = self::provider([
            self::CHAPTER_URL . '1/' . $first . '.json' => self::chapterJson(1, 1),
            self::CHAPTER_URL . '1/' . $last . '.json'  => self::chapterJson(1, 1),
        ]);

        $provider->getPassageFor(new ScriptureReference(booknumber: 101, chapterBegin: $first, chapterEnd: $last), 'kjv');

        $this->assertCount(2, $provider->requested);
    }

    #[TestDox('a chapter below 1 is refused without a request')]
    public function testChapterBelowOneIsRefused(): void
    {
        $provider = self::provider();

        $result = $provider->getPassageFor(new ScriptureReference(booknumber: 101, chapterBegin: -3, chapterEnd: 2), 'kjv');

        $this->assertSame([], $provider->requested);
        $this->assertSame('', $result->text);
    }

    #[TestDox('the deuterocanon is refused without a request')]
    public function testDeuterocanonIsRefused(): void
    {
        // Tobit is Proclaim 167 and has no standard number to put on the wire.
        $provider = self::provider();

        $result = $provider->getPassageFor(new ScriptureReference(booknumber: 167, chapterBegin: 1), 'kjv');

        $this->assertSame([], $provider->requested);
        $this->assertSame('', $result->text);
    }

    #[TestDox('a cached chapter passage is served without a request')]
    public function testCachedChapterPassageIsServed(): void
    {
        $ref      = new ScriptureReference(booknumber: 119, chapterBegin: 23);
        $provider = self::provider([], [
            'getbible-chapter|kjv|' . self::key($ref) => new BiblePassageResult(text: 'cached chapter', reference: 'x'),
        ]);

        $result = $provider->getPassageFor($ref, 'kjv');

        $this->assertSame('cached chapter', $result->text);
        $this->assertSame([], $provider->requested);
    }
}
