<?php

/**
 * Book resolution on a site that is not in English.
 *
 * ⚠️ Every provider is handed the book name the *site displays*.
 * `ScriptureHelper::getBookName()` returns `Text::_()` of the book's key, and
 * this library ships thirteen translations besides en-GB, so on those sites the
 * name arrives as "Žalm" or "Juan". Every provider matched it against a
 * hard-coded English table and got nothing:
 *
 *   ApiBible / BibleBrain  no code to send   -> empty passage
 *   Local                  `book = 0`        -> no verses, and it is the
 *                                               always-available fallback, so
 *                                               nothing else was left to try
 *   GetBible               localised name on the wire, which it does not know
 *
 * So remote and local scripture lookup were both broken outright on any
 * translated site, while the book *number* sat on the row the whole time
 * (#1688).
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\AbstractBibleProvider;
use CWM\Library\Scripture\Bible\BiblePassageResult;
use CWM\Library\Scripture\Bible\Provider\GetBibleProvider;
use CWM\Library\Scripture\Bible\Provider\LocalProvider;
use CWM\Library\Scripture\Helper\ScriptureHelper;
use Joomla\CMS\Language\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class LocalisedBookNameTest extends TestCase
{
    /**
     * Real strings, copied from the shipped language packs.
     *
     * @var array<string, array<string, string>>
     */
    private const PACKS = [
        'cs-CZ' => ['JBS_BBK_PSALM' => 'Žalm', 'JBS_BBK_JOHN' => 'Jan', 'JBS_BBK_REVELATION' => 'Zjevení'],
        'es-ES' => ['JBS_BBK_PSALM' => 'Salmos', 'JBS_BBK_JOHN' => 'Juan', 'JBS_BBK_REVELATION' => 'Apocalipsis'],
        'de-DE' => ['JBS_BBK_PSALM' => 'Psalm', 'JBS_BBK_JOHN' => 'Johannes', 'JBS_BBK_REVELATION' => 'Offenbarung'],
    ];

    /**
     * @return  void
     */
    protected function tearDown(): void
    {
        $this->useLanguage([]);

        parent::tearDown();
    }

    /**
     * Stand a language pack behind Text::_(), and clear what the helper cached
     * from the previous one.
     *
     * @param   array<string, string>  $strings  Key => translated name
     *
     * @return  void
     */
    private function useLanguage(array $strings): void
    {
        Text::$strings = $strings;

        // ⚠️ getTranslatedBookMap() memoises, and loadLanguage() runs once.
        // Without resetting both, every case after the first would silently
        // assert against the first language's map.
        $ref = new \ReflectionClass(ScriptureHelper::class);

        foreach (['translatedBookCache' => null, 'languageLoadAttempted' => false] as $prop => $value) {
            if ($ref->hasProperty($prop)) {
                $ref->getProperty($prop)->setValue(null, $value);
            }
        }
    }

    /**
     * Reaches the protected resolvers without standing up a real provider.
     */
    private static function provider(): AbstractBibleProvider
    {
        return new class () extends AbstractBibleProvider {
            public static function resolve(string $name): string
            {
                return self::resolveBookCode($name);
            }

            public function getPassage(string $reference, string $translation): BiblePassageResult
            {
                return new BiblePassageResult(reference: $reference, translation: $translation);
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

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function localisedNameProvider(): array
    {
        $cases = [];

        foreach (self::PACKS as $tag => $strings) {
            $cases["$tag Psalms"]     = [$tag, $strings['JBS_BBK_PSALM'], 'PSA'];
            $cases["$tag John"]       = [$tag, $strings['JBS_BBK_JOHN'], 'JHN'];
            $cases["$tag Revelation"] = [$tag, $strings['JBS_BBK_REVELATION'], 'REV'];
        }

        return $cases;
    }

    #[DataProvider('localisedNameProvider')]
    #[TestDox('$tag: "$name" resolves to $expected')]
    public function testALocalisedNameResolvesToItsCode(string $tag, string $name, string $expected): void
    {
        $this->useLanguage(self::PACKS[$tag]);

        $this->assertSame(
            $expected,
            self::provider()::resolve($name),
            "\"$name\" is what a $tag site displays, so it is what the provider is handed. Resolving it against "
            . 'the English table alone returns nothing and the passage comes back empty.'
        );
    }

    #[TestDox('English names still resolve when a language pack is loaded')]
    public function testEnglishStillResolvesUnderATranslation(): void
    {
        $this->useLanguage(self::PACKS['cs-CZ']);

        // Content can carry an English name whatever the site language, and the
        // English table is the fallback that has to keep answering for it.
        $this->assertSame('JHN', self::provider()::resolve('John'));
        $this->assertSame('GEN', self::provider()::resolve('Genesis'));
    }

    #[TestDox('Psalms resolves exactly rather than by prefix')]
    public function testPsalmsDoesNotDependOnThePrefixFallback(): void
    {
        // ⚠️ en-GB says "Psalm", BOOK_NAMES says "Psalms". Before #1688 the
        // exact pass missed and only the single-match prefix rule saved it, so
        // adding any other book beginning "psalm" would have broken Psalms.
        $this->useLanguage(['JBS_BBK_PSALM' => 'Psalm']);

        $this->assertSame('PSA', self::provider()::resolve('Psalm'));
        $this->assertSame('PSA', self::provider()::resolve('Psalms'));
    }

    #[TestDox('an ambiguous abbreviation is still refused')]
    public function testAmbiguityIsStillRefused(): void
    {
        $this->useLanguage(self::PACKS['es-ES']);

        // Five books begin "Jo". #43 made that return nothing rather than
        // whichever came first; going through the helper must not undo it.
        $this->assertSame('', self::provider()::resolve('Jo'));
    }

    #[TestDox('the local provider resolves a localised name to a book number')]
    public function testTheLocalProviderResolvesALocalisedName(): void
    {
        $this->useLanguage(self::PACKS['cs-CZ']);

        // ⚠️ The highest-impact instance. LocalProvider queries
        // `#__bsms_bible_verses` by number and is the bundled always-available
        // fallback every other provider falls back *to*, so when it answered 0
        // a translated site had nothing left to try.
        $local = new class () extends LocalProvider {
            public function resolve(string $name): int
            {
                return $this->resolveBookNumber($name);
            }
        };

        $this->assertSame(43, $local->resolve('Jan'), 'Czech for John; the local table is English-only.');
        $this->assertSame(19, $local->resolve('Žalm'));
        $this->assertSame(43, $local->resolve('John'), 'English must keep working under a translation.');
    }

    #[TestDox('GetBible is sent an English book name, not the site\'s')]
    public function testGetBibleIsSentAnEnglishName(): void
    {
        $this->useLanguage(self::PACKS['es-ES']);

        // ⚠️ Unlike the others this API takes a book *name* on the wire, so the
        // fix is a different one: translate back to English rather than to a code.
        $getBible = new class () extends GetBibleProvider {
            public static function anglicize(string $reference): string
            {
                return self::anglicizeBookName($reference);
            }
        };

        $this->assertSame('John 3:16', $getBible::anglicize('Juan 3:16'));
        $this->assertSame('Psalms 23:1-6', $getBible::anglicize('Salmos 23:1-6'));

        // A name the library cannot place goes through untouched rather than
        // being dropped.
        $this->assertSame('Nonesuch 1:1', $getBible::anglicize('Nonesuch 1:1'));
    }

    #[TestDox('the URL GetBible requests carries the English name')]
    public function testTheRequestedUrlCarriesTheEnglishName(): void
    {
        $this->useLanguage(self::PACKS['es-ES']);

        // ⚠️ Asserted on the URL, not on anglicizeBookName(). Testing the helper
        // alone passes whether or not getPassage() calls it -- verified by
        // deleting the call and watching the helper's own test stay green.
        $getBible = new class () extends GetBibleProvider {
            public string $requested = '';

            protected function httpGet(string $url, int $timeout = 10): ?string
            {
                $this->requested = $url;

                return null;
            }

            protected function readCache(string $provider, string $translation, string $reference): ?BiblePassageResult
            {
                return null;
            }
        };

        $getBible->getPassage('Juan+3:16', 'kjv');

        $this->assertStringContainsString(
            'John',
            $getBible->requested,
            'The site name went to the API unchanged, so a Spanish site asks GetBible for a book it cannot name.'
        );
        $this->assertStringNotContainsString('Juan', $getBible->requested);
    }

    #[TestDox('the language stub is actually in effect, so these cases are real')]
    public function testTheLanguageStubWorks(): void
    {
        $this->useLanguage(self::PACKS['cs-CZ']);

        $this->assertSame(
            'Žalm',
            ScriptureHelper::getBookName(119),
            'Text::_() is not returning the stubbed pack, so every case above is quietly testing English.'
        );
    }
}
