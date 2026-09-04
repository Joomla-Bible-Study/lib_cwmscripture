<?php

/**
 * The public methods consumers call, which must not vanish in a refactor.
 *
 * `registerLogger()` was removed by #50 — a namespace refactor that had nothing
 * to do with logging — and nothing in this repository failed. It surfaced two
 * releases later as a fatal on any Proclaim front-end page that rendered
 * scripture, because Proclaim calls it from two site helpers and the
 * ScriptureLinks content plugin calls it twice.
 *
 * The quieter half was worse: this class still logs to `cwmscripture.bible` in
 * half a dozen places, and Joomla discards entries for a category no logger is
 * registered for. So scripture diagnostics went nowhere for two releases with
 * no error anywhere.
 *
 * A library cannot see its consumers, so the contract has to be asserted here.
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Bible;

use CWM\Library\Scripture\Bible\AbstractBibleProvider;
use CWM\Library\Scripture\Importer\BibleImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ConsumerApiContractTest extends TestCase
{
    /**
     * Methods known to be called from outside this library.
     *
     * Removing one is a breaking change and needs a deprecation cycle, whatever
     * the refactor that prompts it. Add to this list when a consumer starts
     * depending on something new.
     *
     * @return array<string, array{0: string}>
     */
    public static function consumerFacingMethods(): array
    {
        return [
            // Proclaim: Cwmshowscripture, CwmscriptureLookupHelper.
            // ScriptureLinks: the content plugin, twice.
            'registerLogger'     => ['registerLogger'],
            // Proclaim: Cwmshowscripture:97, CwmscriptureLookupHelper:54.
            'setCacheTtl'        => ['setCacheTtl'],
            // Proclaim: CwmscriptureLookupHelper:58.
            'isLastErrorTransient' => ['isLastErrorTransient'],
            'proclaimToStandard' => ['proclaimToStandard'],
            'standardToProclaim' => ['standardToProclaim'],
            'bookCode'           => ['bookCode'],
            'getBookName'        => ['getBookName'],
            'bookNames'          => ['bookNames'],
        ];
    }

    #[DataProvider('consumerFacingMethods')]
    #[TestDox('$method() is still callable from outside the library')]
    public function testConsumerFacingMethodsStillExist(string $method): void
    {
        self::assertTrue(
            method_exists(AbstractBibleProvider::class, $method),
            "AbstractBibleProvider::{$method}() is called by code outside this repository. Removing it breaks "
            . 'that code at runtime, with nothing here to catch it. Deprecate it for a release before removing.'
        );

        $reflection = new \ReflectionMethod(AbstractBibleProvider::class, $method);

        self::assertTrue($reflection->isPublic(), "{$method}() must stay public.");
        // registerLogger and the book-code helpers are static; the instance
        // accessors are not. Both shapes are called from outside, so assert the
        // method exists and is reachable rather than forcing one form.
        self::assertFalse(
            $reflection->isPrivate(),
            "{$method}() must stay reachable from outside this library."
        );
    }

    /**
     * The pairing that actually broke: this class logs to a category, so it has
     * to offer a way to register a logger for it. Losing either half silently
     * discards everything logged.
     */
    #[TestDox('a logger can be registered for the category this class logs to')]
    public function testTheLoggedCategoryCanBeRegistered(): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Bible/AbstractBibleProvider.php'
        );

        self::assertStringContainsString(
            "'cwmscripture.bible'",
            $source,
            'Sanity check: this class is expected to log to that category.'
        );
        self::assertStringContainsString(
            'Log::addLogger(',
            $source,
            'This class logs to cwmscripture.bible but never registers a logger for it, so Joomla discards '
            . 'every entry. That is how two releases shipped with scripture logging silently dead.'
        );
    }

    /** Documented as safe to call repeatedly, and consumers do. */
    #[TestDox('registering the logger twice is harmless')]
    public function testRegisteringTwiceIsSafe(): void
    {
        AbstractBibleProvider::registerLogger();
        AbstractBibleProvider::registerLogger();

        $this->expectNotToPerformAssertions();
    }

    /**
     * The refactor deleted two properties as well as the methods, and left
     * every read and write of them in place.
     *
     * `$cacheTtl` read as null, so `time() + $this->cacheTtl` became `time()`
     * and every cached passage expired the moment it was written. Writing to an
     * undeclared `$lastErrorTransient` is deprecated in PHP 8.2 and an error in
     * PHP 9, so it was a forward-compatibility break too. Neither produced a
     * failure here, which is why this test exists.
     *
     * @return void
     */
    #[TestDox('the properties this class reads and writes are actually declared')]
    public function testTheBackingPropertiesAreDeclared(): void
    {
        $reflection = new \ReflectionClass(AbstractBibleProvider::class);
        $declared   = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties()
        );

        foreach (['cacheTtl', 'lastErrorTransient'] as $property) {
            self::assertContains(
                $property,
                $declared,
                "\${$property} is read or written by this class. An undeclared property reads as null and "
                . 'writing to one is deprecated in PHP 8.2, so losing the declaration breaks behaviour silently.'
            );
        }
    }

    /**
     * BibleImporter methods the translations-manager UI calls through its
     * consumer's AJAX endpoints.
     *
     * ⚠️ This library ships the JavaScript that posts to those endpoints
     * (`bible-translations.js` calls `removeAllTranslations` and
     * `cleanupProvider`), but the endpoints themselves live in the
     * ScriptureLinks plugin, which forwards to this class. Two of them
     * forwarded to methods that were never written, so both buttons answered a
     * bodyless 500 and the UI simply hung — CWMScriptureLinks#46.
     *
     * @return array<string, array{0: string}>
     */
    public static function importerMethodsUsedByConsumers(): array
    {
        return [
            // ScriptureLinks: ajaxRemoveAllTranslations().
            'removeAllTranslations' => ['removeAllTranslations'],
            // ScriptureLinks: ajaxCleanupProvider(), on provider disable.
            'removeProviderEntries' => ['removeProviderEntries'],
            // ScriptureLinks: the per-row remove action.
            'removeTranslation'     => ['removeTranslation'],
            'isCoreTranslation'     => ['isCoreTranslation'],
            'isInstalled'           => ['isInstalled'],
            'downloadAndImport'     => ['downloadAndImport'],
            'seedGetBibleCatalog'   => ['seedGetBibleCatalog'],
        ];
    }

    #[DataProvider('importerMethodsUsedByConsumers')]
    #[TestDox('BibleImporter::$method() is still callable from outside the library')]
    public function testImporterMethodsUsedByConsumersExist(string $method): void
    {
        self::assertTrue(
            method_exists(BibleImporter::class, $method),
            "BibleImporter::{$method}() is called by code outside this repository. A missing one is not a "
            . 'compile error — it is an Error at click time, and the consumer catches \Exception, so the '
            . 'browser gets a 500 with no body.'
        );

        $reflection = new \ReflectionMethod(BibleImporter::class, $method);

        self::assertTrue($reflection->isPublic(), "BibleImporter::{$method}() must stay public.");
        self::assertTrue($reflection->isStatic(), "BibleImporter::{$method}() is called statically by consumers.");
    }
}
