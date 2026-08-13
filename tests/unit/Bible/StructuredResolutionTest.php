<?php

/**
 * Unit tests for what the structured entry point resolves to
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\BiblePassageResult;
use CWM\Library\Scripture\Bible\Provider\BibleBrainProvider;
use CWM\Library\Scripture\Bible\Provider\LocalProvider;
use CWM\Library\Scripture\Helper\ScriptureReference;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Captures what `getPassageFor()` hands to the shared fetch, without a database
 * or an HTTP call.
 *
 * The property under test is that each provider converts the reference into the
 * representation *it* needs. Getting that wrong does not throw — it queries a
 * book that does not exist and returns an empty passage, which is exactly how
 * the localisation bug in Proclaim#1688 hid for months.
 *
 * @since  1.1.13
 */
class StructuredResolutionTest extends TestCase
{
    #[TestDox('⚠️ LocalProvider converts Proclaim numbering to standard')]
    public function testLocalConvertsNumbering(): void
    {
        $provider = new class () extends LocalProvider {
            /** @var array<string, int> */
            public array $captured = [];

            protected function queryVerses(
                array $parsed,
                string $reference,
                string $translation
            ): BiblePassageResult {
                $this->captured = $parsed;

                return new BiblePassageResult(reference: $reference, translation: $translation);
            }
        };

        // John is Proclaim 143 and standard 43. `#__bsms_bible_verses.book` is
        // keyed 1-66, so handing it 143 would match no rows and return an empty
        // passage rather than failing.
        $provider->getPassageFor(
            new ScriptureReference(booknumber: 143, chapterBegin: 3, verseBegin: 16, chapterEnd: 3, verseEnd: 16),
            'kjv'
        );

        $this->assertSame(43, $provider->captured['book'], 'must be the standard number, not the Proclaim one');
        $this->assertNotSame(143, $provider->captured['book']);
        $this->assertSame(3, $provider->captured['chapter_begin']);
        $this->assertSame(16, $provider->captured['verse_begin']);
    }

    #[TestDox('LocalProvider refuses the deuterocanon rather than querying book 0')]
    public function testLocalRefusesDeuterocanon(): void
    {
        $provider = new class () extends LocalProvider {
            public bool $queried = false;

            protected function queryVerses(
                array $parsed,
                string $reference,
                string $translation
            ): BiblePassageResult {
                $this->queried = true;

                return new BiblePassageResult(reference: $reference, translation: $translation);
            }
        };

        // Tobit has no standard number and no rows locally. Querying book 0
        // would silently return nothing; refusing says so in the log instead.
        $result = $provider->getPassageFor(
            new ScriptureReference(booknumber: 167, chapterBegin: 1, verseBegin: 1),
            'kjv'
        );

        $this->assertFalse($provider->queried, 'must not reach the database for a book it cannot hold');
        $this->assertSame('', $result->text);
    }

    #[TestDox('BibleBrainProvider resolves the book code without a name')]
    public function testBibleBrainResolvesCode(): void
    {
        $provider = new class ('test-key') extends BibleBrainProvider {
            /** Cache lookups need a database the unit environment has none of. */
            protected function readCache(
                string $provider,
                string $translation,
                string $reference
            ): ?BiblePassageResult {
                return null;
            }

            /** @var array<string, mixed> */
            public array $captured = [];

            protected function fetchParsed(
                array $parsed,
                string $reference,
                string $translation
            ): BiblePassageResult {
                $this->captured = $parsed;

                return new BiblePassageResult(reference: $reference, translation: $translation);
            }
        };

        $provider->getPassageFor(
            new ScriptureReference(booknumber: 143, chapterBegin: 3, verseBegin: 16, chapterEnd: 3, verseEnd: 16),
            'ENGKJV'
        );

        $this->assertSame('JHN', $provider->captured['book']);
        $this->assertSame(3, $provider->captured['chapter']);
        $this->assertSame(16, $provider->captured['verse_start']);
    }

    #[TestDox('BibleBrainProvider reaches the deuterocanon, unlike LocalProvider')]
    public function testBibleBrainReachesDeuterocanon(): void
    {
        $provider = new class ('test-key') extends BibleBrainProvider {
            /** Cache lookups need a database the unit environment has none of. */
            protected function readCache(
                string $provider,
                string $translation,
                string $reference
            ): ?BiblePassageResult {
                return null;
            }

            /** @var array<string, mixed> */
            public array $captured = [];

            protected function fetchParsed(
                array $parsed,
                string $reference,
                string $translation
            ): BiblePassageResult {
                $this->captured = $parsed;

                return new BiblePassageResult(reference: $reference, translation: $translation);
            }
        };

        // A remote provider can serve Tobit if the fileset has it; the local
        // table cannot. The two correctly disagree.
        $provider->getPassageFor(
            new ScriptureReference(booknumber: 167, chapterBegin: 1, verseBegin: 1),
            'ENGKJV'
        );

        $this->assertSame('TOB', $provider->captured['book']);
    }
}
