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
        self::assertTrue($reflection->isStatic(), "{$method}() is called statically.");
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
}
