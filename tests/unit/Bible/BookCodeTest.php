<?php

/**
 * Book code resolution shared by the OSIS and USFM providers.
 *
 * API.Bible sends `JHN.3.16`, Bible Brain sends `/JHN/3` — the same code, and
 * both providers used to carry byte-identical copies of the table plus their own
 * resolver. Bible Brain's fell back to a prefix match that answered with
 * whichever book came first, so "Jo" resolved to Joshua and never John;
 * API.Bible had no fallback at all, so the two disagreed for the same input
 * (#1688).
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\AbstractBibleProvider;
use CWM\Library\Scripture\Bible\BiblePassageResult;
use CWM\Library\Scripture\Book\BookCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class BookCodeTest extends TestCase
{
    /**
     * Reaches the protected statics without standing up a real provider.
     */
    private static function provider(): AbstractBibleProvider
    {
        return new class () extends AbstractBibleProvider {
            public static function resolve(string $name): string
            {
                return self::resolveBookCode($name);
            }

            public static function byStandard(int $n): string
            {
                return self::bookCode($n);
            }

            public static function byProclaim(int $n): string
            {
                return self::bookCodeForProclaim($n);
            }

            public function getPassage(string $reference, string $translation): BiblePassageResult
            {
                throw new \RuntimeException('not used');
            }

            public function getAvailableTranslations(): array
            {
                return [];
            }

            public function returnsText(): bool
            {
                return true;
            }

            public function isOfflineCapable(): bool
            {
                return false;
            }

            public function getName(): string
            {
                return 'test';
            }
        };
    }

    public static function exactNames(): array
    {
        return [
            'canonical'     => ['John', 'JHN'],
            'lowercase'     => ['john', 'JHN'],
            'uppercase'     => ['JOHN', 'JHN'],
            'padded'        => ['  John  ', 'JHN'],
            'multi-word'    => ['Song of Solomon', 'SNG'],
            'numbered book' => ['1 Corinthians', '1CO'],
        ];
    }

    #[DataProvider('exactNames')]
    #[TestDox('an exact book name resolves to its code')]
    public function testExactNamesResolve(string $input, string $expected): void
    {
        $this->assertSame($expected, self::provider()::resolve($input));
    }

    public static function unambiguousAbbreviations(): array
    {
        return [
            'Joh -> John'    => ['Joh', 'JHN'],
            'Rom -> Romans'  => ['Rom', 'ROM'],
            'Gen -> Genesis' => ['Gen', 'GEN'],
            'Rev -> Rev'     => ['Rev', 'REV'],
        ];
    }

    #[DataProvider('unambiguousAbbreviations')]
    #[TestDox('an abbreviation matching exactly one book resolves')]
    public function testUnambiguousAbbreviationsResolve(string $input, string $expected): void
    {
        $this->assertSame($expected, self::provider()::resolve($input));
    }

    public static function ambiguousAbbreviations(): array
    {
        return [
            // Joshua, Job, Joel, Jonah, John
            'Jo' => ['Jo'],
            // Judges, Jude
            'Jud' => ['Jud'],
        ];
    }

    #[DataProvider('ambiguousAbbreviations')]
    #[TestDox('an abbreviation matching several books resolves to nothing, not to the first')]
    public function testAmbiguousAbbreviationsAreRefused(string $input): void
    {
        $this->assertSame(
            '',
            self::provider()::resolve($input),
            'A prefix matching more than one book must not answer. Returning whichever came '
            . 'first in the table is how "Jo" became Joshua and never John.'
        );
    }

    #[TestDox('unknown and empty input resolve to nothing')]
    public function testUnknownInputResolvesToNothing(): void
    {
        $p = self::provider();

        $this->assertSame('', $p::resolve('nonsense'));
        $this->assertSame('', $p::resolve(''));
        $this->assertSame('', $p::resolve('   '));
    }

    #[TestDox('codes are addressable by standard and by Proclaim book number')]
    public function testCodesByNumber(): void
    {
        $p = self::provider();

        $this->assertSame('JHN', $p::byStandard(43));
        $this->assertSame('GEN', $p::byStandard(1));
        $this->assertSame('REV', $p::byStandard(66));
        $this->assertSame('', $p::byStandard(0));
        $this->assertSame('', $p::byStandard(67));

        // Proclaim numbers the canon from 101.
        $this->assertSame('JHN', $p::byProclaim(143));
        $this->assertSame('GEN', $p::byProclaim(101));

        // The deuterocanon has no standard number, so it is answered from its
        // own table keyed by Proclaim number rather than through
        // proclaimToStandard(), which stops at 166.
        $this->assertSame('TOB', $p::byProclaim(167));
        $this->assertSame('BAR', $p::byProclaim(173));

        // Still nothing either side of the range.
        $this->assertSame('', $p::byProclaim(166 + 8));
        $this->assertSame('', $p::byProclaim(0));
    }

    #[TestDox('every deuterocanonical book maps to its USFM code')]
    public function testDeuterocanonCodes(): void
    {
        $p = self::provider();

        // Order matters and is not alphabetical: Maccabees sits between Judith
        // and Wisdom in BOOK_KEYS, so a mapping written from the OSIS list in
        // issue #1688 would shift four of these seven onto the wrong book.
        $expected = [
            167 => 'TOB',
            168 => 'JDT',
            169 => '1MA',
            170 => '2MA',
            171 => 'WIS',
            172 => 'SIR',
            173 => 'BAR',
        ];

        foreach ($expected as $proclaimBook => $code) {
            $this->assertSame(
                $code,
                $p::byProclaim($proclaimBook),
                "Proclaim book $proclaimBook should resolve to $code"
            );
        }

        // USFM, not OSIS: one table serves both providers, and they agree on the
        // canon but not here — OSIS writes 1Macc where USFM writes 1MA.
        $this->assertNotSame('1Macc', $p::byProclaim(169));
    }

    #[TestDox('every canonical book has a code, and no code is duplicated')]
    public function testTableIsCompleteAndUnique(): void
    {
        $p     = self::provider();
        $codes = [];

        for ($n = 1; $n <= 66; $n++) {
            $code = $p::byStandard($n);

            $this->assertNotSame('', $code, "Standard book {$n} has no code.");
            $codes[] = $code;
        }

        $this->assertSame(
            \count($codes),
            \count(array_unique($codes)),
            'Two books share a code, so one of them can never be addressed.'
        );
    }

    #[TestDox('the whole name table is reachable without reflection')]
    public function testBookNamesIsPubliclyAccessible(): void
    {
        // The delegate exists because there was no supported way to read the
        // table: BOOK_NAMES was protected, so CWMLivingWord scanned it by
        // reflection and moving it into BookCodes broke that consumer silently
        // (CWMLivingWord#118). A public call is the whole point — if this ever
        // stops being callable from outside the hierarchy, the gap is back.
        $names = AbstractBibleProvider::bookNames();

        $this->assertCount(66, $names);
        $this->assertSame(BookCodes::names(), $names);
        $this->assertSame('Genesis', $names[1]);
        $this->assertSame('John', $names[43]);
        $this->assertSame('Revelation', $names[66]);

        // Same table getBookName() answers from, one book at a time.
        foreach ($names as $number => $name) {
            $this->assertSame($name, AbstractBibleProvider::getBookName($number));
        }
    }
}
