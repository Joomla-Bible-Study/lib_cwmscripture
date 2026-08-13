<?php

/**
 * Characterisation tests for ScriptureHelper::parseReference()
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Helper;

use CWM\Library\Scripture\Helper\ScriptureHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pins what `parseReference()` accepts, ahead of the providers delegating to it.
 *
 * Three reference dialects exist today, and they do not agree:
 *
 * | form            | ScriptureHelper | LocalProvider | ApiBibleProvider |
 * |-----------------|-----------------|---------------|------------------|
 * | `John 3:16`     | yes             | yes           | yes              |
 * | `John 3`        | yes             | yes           | no (needs verse) |
 * | `John 3-4`      | **no**          | yes           | no               |
 * | `John+3:16`     | see below       | yes           | yes              |
 *
 * Each provider parses the string itself, so converting one to take a
 * `ScriptureReference` means routing it through this method instead. Anything
 * this method rejects that the provider accepted today silently stops working —
 * which is what these tests exist to catch.
 *
 * `+` normalisation is now handled here. The chapter-range form is still not,
 * and `testChapterRangeIsStillUnsupported()` asserts that deliberately so the
 * gap cannot be forgotten when LocalProvider is converted.
 *
 * @since  __DEPLOY_VERSION__
 */
class ReferenceParseCompatibilityTest extends TestCase
{
    /**
     * Forms every dialect accepts, which must keep working unchanged.
     *
     * @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int}>
     */
    public static function sharedFormProvider(): array
    {
        // reference => [booknumber, chapterBegin, verseBegin, chapterEnd, verseEnd]
        return [
            'single verse'        => ['John 3:16', 143, 3, 16, 3, 16],
            'verse range'         => ['Luke 7:36-38', 142, 7, 36, 7, 38],
            'cross-chapter range' => ['John 3:16-4:2', 143, 3, 16, 4, 2],
            'spaced range'        => ['Luke 7:36 - 38', 142, 7, 36, 7, 38],
            'numbered book'       => ['1 John 2:1', 162, 2, 1, 2, 1],
            'multi-word book'     => ['Song of Solomon 2:1', 122, 2, 1, 2, 1],
            'abbreviation'        => ['Gen 1:1', 101, 1, 1, 1, 1],
        ];
    }

    #[DataProvider('sharedFormProvider')]
    #[TestDox('"$reference" parses the same for every dialect')]
    public function testSharedFormsParse(
        string $reference,
        int $book,
        int $chapterBegin,
        int $verseBegin,
        int $chapterEnd,
        int $verseEnd
    ): void {
        $ref = ScriptureHelper::parseReference($reference);

        $this->assertNotNull($ref, "\"$reference\" must parse — every provider accepts it today");
        $this->assertSame($book, $ref->booknumber);
        $this->assertSame($chapterBegin, $ref->chapterBegin);
        $this->assertSame($verseBegin, $ref->verseBegin);
        $this->assertSame($chapterEnd, $ref->chapterEnd);
        $this->assertSame($verseEnd, $ref->verseEnd);
    }

    #[TestDox('a + separator is normalised, as the providers already do')]
    public function testPlusSeparatorIsNormalised(): void
    {
        // ApiBibleProvider::buildPassageId() does str_replace('+', ' ') before
        // matching, so URL-style references reach it and work. A provider
        // delegating here would have rejected them.
        $ref = ScriptureHelper::parseReference('John+3:16');

        $this->assertNotNull($ref, 'a + separated reference must parse');
        $this->assertSame(143, $ref->booknumber);
        $this->assertSame(3, $ref->chapterBegin);
        $this->assertSame(16, $ref->verseBegin);

        // Multi-word book names survive it too.
        $multi = ScriptureHelper::parseReference('Song+of+Solomon+2:1');
        $this->assertNotNull($multi);
        $this->assertSame(122, $multi->booknumber);
    }

    #[TestDox('a chapter-only reference parses with no verse')]
    public function testChapterOnly(): void
    {
        // LocalProvider accepts this; ApiBibleProvider does not, because
        // buildPassageId()'s regex requires a verse. Converting ApiBible must
        // decide what to do rather than inherit this silently.
        $ref = ScriptureHelper::parseReference('Psalm 23');

        $this->assertNotNull($ref);
        $this->assertSame(119, $ref->booknumber);
        $this->assertSame(23, $ref->chapterBegin);
        $this->assertSame(0, $ref->verseBegin);
        $this->assertSame(0, $ref->verseEnd);
    }

    #[TestDox('⚠️ a verse-less chapter range is still unsupported')]
    public function testChapterRangeIsStillUnsupported(): void
    {
        // Asserted, not skipped: LocalProvider accepts "John 3-4" and this does
        // not, so converting LocalProvider (issue #1688 item 3) must widen the
        // regex first or that form silently stops resolving.
        //
        // Note the two disagree on meaning as well as support. LocalProvider
        // reads "John 3-4" as chapter 3 up to verse 4; the conventional reading
        // is chapters 3 to 4. Whichever is chosen is a behaviour decision, not a
        // parsing detail, which is why it is not being made here.
        $this->assertNull(
            ScriptureHelper::parseReference('John 3-4'),
            'still unsupported — widen REFERENCE_REGEX before converting LocalProvider'
        );
    }

    #[TestDox('unparseable input returns null rather than a zeroed reference')]
    public function testRejectsGarbage(): void
    {
        $this->assertNull(ScriptureHelper::parseReference('NotABook 1:1'));
        $this->assertNull(ScriptureHelper::parseReference(''));
        $this->assertNull(ScriptureHelper::parseReference('   '));
        $this->assertNull(ScriptureHelper::parseReference('just some text'));

        // A bare "+" must not become a parseable reference through normalisation.
        $this->assertNull(ScriptureHelper::parseReference('+'));
    }
}
