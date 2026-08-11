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

        // The deuterocanon (167-173) has no entry in the canonical 66, so it
        // resolves to nothing rather than to a neighbouring book.
        $this->assertSame('', $p::byProclaim(167));
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
}
