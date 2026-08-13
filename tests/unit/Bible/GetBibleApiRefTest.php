<?php

/**
 * Unit tests for GetBibleProvider's structured API reference builder
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\Provider\GetBibleProvider;
use CWM\Library\Scripture\Helper\ScriptureReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * GetBible takes a book *name* on the wire, so it is the provider the round trip
 * hurt most: a caller rendered the number to a localised name, and
 * `anglicizeBookName()` parsed that back to a number to recover the English one.
 *
 * @since  __DEPLOY_VERSION__
 */
class GetBibleApiRefTest extends TestCase
{
    /**
     * Reach the private static builder without standing up an HTTP call.
     *
     * @param   ScriptureReference  $ref      Parsed reference
     * @param   string              $english  English book name
     *
     * @return  string
     */
    private static function build(ScriptureReference $ref, string $english): string
    {
        $method = new \ReflectionMethod(GetBibleProvider::class, 'apiRefFromParts');

        return $method->invoke(null, $english, $ref);
    }

    /**
     * @return array<string, array{0: ScriptureReference, 1: string, 2: string}>
     */
    public static function shapeProvider(): array
    {
        return [
            'single verse' => [
                new ScriptureReference(booknumber: 143, chapterBegin: 3, verseBegin: 16, chapterEnd: 3, verseEnd: 16),
                'John',
                'John 3:16',
            ],
            'verse range' => [
                new ScriptureReference(booknumber: 142, chapterBegin: 7, verseBegin: 36, chapterEnd: 7, verseEnd: 38),
                'Luke',
                'Luke 7:36-38',
            ],
            'cross-chapter range' => [
                new ScriptureReference(booknumber: 143, chapterBegin: 3, verseBegin: 16, chapterEnd: 4, verseEnd: 2),
                'John',
                'John 3:16-4:2',
            ],
            'chapter only' => [
                new ScriptureReference(booknumber: 119, chapterBegin: 23),
                'Psalms',
                'Psalms 23',
            ],
            'single verse, no explicit end' => [
                new ScriptureReference(booknumber: 145, chapterBegin: 8, verseBegin: 28),
                'Romans',
                'Romans 8:28',
            ],
        ];
    }

    #[DataProvider('shapeProvider')]
    #[TestDox('builds "$expected"')]
    public function testShapes(ScriptureReference $ref, string $english, string $expected): void
    {
        $this->assertSame($expected, self::build($ref, $english));
    }

    #[TestDox('a same-chapter range never grows a redundant chapter prefix')]
    public function testSameChapterRangeNeedsNoTidyUp(): void
    {
        // getPassage() produces "Luke 12:54-12:56" from a string and then has a
        // preg_replace_callback to strip the repeated chapter back out. Building
        // from parts, the redundant form is never created in the first place —
        // the tidy-up is not skipped, it is unreachable.
        $ref = new ScriptureReference(booknumber: 142, chapterBegin: 12, verseBegin: 54, chapterEnd: 12, verseEnd: 56);

        $built = self::build($ref, 'Luke');

        $this->assertSame('Luke 12:54-56', $built);
        $this->assertStringNotContainsString('12:54-12:', $built);
    }

    #[TestDox('an end verse that does not extend the range is omitted')]
    public function testDegenerateRangeIsOmitted(): void
    {
        // verseEnd == verseBegin in the same chapter is a single verse, not a
        // range, and GetBible should not be sent "John 3:16-16".
        $ref = new ScriptureReference(booknumber: 143, chapterBegin: 3, verseBegin: 16, chapterEnd: 3, verseEnd: 16);

        $this->assertSame('John 3:16', self::build($ref, 'John'));
    }
}
