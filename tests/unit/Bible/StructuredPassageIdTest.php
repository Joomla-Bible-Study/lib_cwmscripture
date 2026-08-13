<?php

/**
 * Unit tests for ApiBibleProvider's structured passage-id builder
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\Provider\ApiBibleProvider;
use CWM\Library\Scripture\Helper\ScriptureHelper;
use CWM\Library\Scripture\Helper\ScriptureReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `buildPassageIdFor()` takes the book code straight off the book number;
 * `buildPassageId()` has to recover it from a name. They must still agree.
 *
 * That equivalence is the whole safety argument for converting a provider to the
 * structured entry point (#1688 item 3) — if the two ever disagree, the same
 * reference returns different scripture depending on which entry point a caller
 * happened to use.
 *
 * @since  __DEPLOY_VERSION__
 */
class StructuredPassageIdTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function referenceProvider(): array
    {
        return [
            'single verse'        => ['John 3:16'],
            'verse range'         => ['Luke 7:36-38'],
            'cross-chapter range' => ['John 3:16-4:2'],
            'numbered book'       => ['1 John 2:1'],
            'multi-word book'     => ['Song of Solomon 2:1'],
            'long cross-chapter'  => ['Revelation 21:1-22:5'],
            'plus separated'      => ['John+3:16'],
        ];
    }

    #[DataProvider('referenceProvider')]
    #[TestDox('"$reference" builds the same id from a string and from a reference')]
    public function testBothBuildersAgree(string $reference): void
    {
        $provider = new ApiBibleProvider();

        $fromString = $provider->buildPassageId($reference);
        $parsed     = ScriptureHelper::parseReference($reference);

        $this->assertNotNull($parsed, "\"$reference\" must parse");
        $this->assertNotSame('', $fromString, "\"$reference\" must build an id from the string path");

        $this->assertSame(
            $fromString,
            $provider->buildPassageIdFor($parsed),
            'the structured builder must agree with the string builder, or the same '
            . 'reference returns different scripture depending on the entry point used'
        );
    }

    #[TestDox('the deuterocanon builds an id structurally')]
    public function testDeuterocanonBuildsStructurally(): void
    {
        $provider = new ApiBibleProvider();

        // Tobit is Proclaim book 167. Before DEUTEROCANON_CODES this produced ''
        // because proclaimToStandard() stops at 166.
        $ref = new ScriptureReference(booknumber: 167, chapterBegin: 1, verseBegin: 1, chapterEnd: 1, verseEnd: 1);

        $this->assertSame('TOB.1.1', $provider->buildPassageIdFor($ref));
    }

    #[TestDox('a reference with no verse builds nothing, matching the string path')]
    public function testChapterOnlyBuildsNothing(): void
    {
        $provider = new ApiBibleProvider();

        // buildPassageId()'s regex requires a verse, so the string path returns
        // '' for a chapter-only reference. The structured path must match rather
        // than quietly gaining a capability the other entry point lacks.
        $this->assertSame('', $provider->buildPassageId('Psalm 23'));

        $ref = ScriptureHelper::parseReference('Psalm 23');
        $this->assertNotNull($ref);
        $this->assertSame('', $provider->buildPassageIdFor($ref));
    }

    #[TestDox('an unknown book builds nothing')]
    public function testUnknownBookBuildsNothing(): void
    {
        $provider = new ApiBibleProvider();

        $ref = new ScriptureReference(booknumber: 0, chapterBegin: 1, verseBegin: 1);
        $this->assertSame('', $provider->buildPassageIdFor($ref));

        // 174 is past the deuterocanon, so neither table answers.
        $past = new ScriptureReference(booknumber: 174, chapterBegin: 1, verseBegin: 1);
        $this->assertSame('', $provider->buildPassageIdFor($past));
    }
}
