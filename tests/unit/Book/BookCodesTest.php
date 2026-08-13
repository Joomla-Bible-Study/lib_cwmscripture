<?php

/**
 * Unit tests for Book\BookCodes
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Book;

use CWM\Library\Scripture\Book\BookCodes;
use CWM\Library\Scripture\Helper\ScriptureReference;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Covers the extracted reference data and, more importantly, the property that
 * made extracting it worthwhile: `Book\` depends on nothing.
 *
 * @since  __DEPLOY_VERSION__
 */
class BookCodesTest extends TestCase
{
    #[TestDox('⚠️ Book\\ depends on neither Helper\\ nor Bible\\')]
    public function testBookNamespaceHasNoDependencies(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/src/Book/BookCodes.php');

        $this->assertNotFalse($source);

        // The whole point of the namespace. Helper and Bible both depend on
        // Book, so a dependency in this direction is a cycle — and the tempting
        // one is moving resolveBookCode() here, which needs
        // ScriptureHelper::getBookNumber(). Pure number <-> code lives here;
        // anything needing name resolution does not.
        $this->assertStringNotContainsString(
            'Scripture\\Helper',
            $source,
            'Book\\ must not reference Helper\\ — Helper depends on Book, so this would be a cycle'
        );
        $this->assertStringNotContainsString(
            'Scripture\\Bible',
            $source,
            'Book\\ must not reference Bible\\ — Bible depends on Book, so this would be a cycle'
        );
    }

    #[TestDox('the canonical tables are complete and aligned')]
    public function testTablesAreComplete(): void
    {
        $this->assertCount(66, BookCodes::names());
        $this->assertCount(66, BookCodes::codes());

        // Every standard number resolves in both tables, and codes are unique.
        for ($i = 1; $i <= 66; $i++) {
            $this->assertNotSame('', BookCodes::name($i), "book $i has no name");
            $this->assertNotSame('', BookCodes::code($i), "book $i has no code");
        }

        $this->assertSame(66, \count(array_unique(BookCodes::codes())), 'codes must be unique');
    }

    #[TestDox('number conversion round-trips across the canon')]
    public function testNumberConversion(): void
    {
        for ($standard = 1; $standard <= 66; $standard++) {
            $proclaim = BookCodes::toProclaim($standard);
            $this->assertSame($standard + 100, $proclaim);
            $this->assertSame($standard, BookCodes::toStandard($proclaim));
        }

        // The deuterocanon has no standard number, which is why forProclaim()
        // consults its own table first.
        $this->assertSame(0, BookCodes::toStandard(167));
        $this->assertSame(0, BookCodes::toStandard(0));
    }

    #[TestDox('the deuterocanon resolves by Proclaim number')]
    public function testDeuterocanon(): void
    {
        $expected = [167 => 'TOB', 168 => 'JDT', 169 => '1MA', 170 => '2MA', 171 => 'WIS', 172 => 'SIR', 173 => 'BAR'];

        foreach ($expected as $book => $code) {
            $this->assertSame($code, BookCodes::forProclaim($book), "book $book");
        }

        $this->assertSame('', BookCodes::forProclaim(174));
        $this->assertSame('', BookCodes::forProclaim(0));
    }

    #[TestDox('a ScriptureReference can name its own book code')]
    public function testReferenceExposesItsCode(): void
    {
        // Proclaim#1688 item 4. Only possible once the tables left the provider
        // base class: Helper reaching into Bible would have been a cycle.
        $john = new ScriptureReference(booknumber: 143, chapterBegin: 3, verseBegin: 16);
        $this->assertSame('JHN', $john->bookCode());

        $tobit = new ScriptureReference(booknumber: 167, chapterBegin: 1, verseBegin: 1);
        $this->assertSame('TOB', $tobit->bookCode());

        $unknown = new ScriptureReference();
        $this->assertSame('', $unknown->bookCode());
    }
}
