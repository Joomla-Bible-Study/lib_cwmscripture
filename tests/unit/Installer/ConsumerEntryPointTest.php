<?php

/**
 * The consumer entry point is a published API: three repositories call it by
 * path from their install scripts. Its shape and its location are both contracts,
 * so both are asserted here rather than trusted.
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Installer;

use PHPUnit\Framework\TestCase;

class ConsumerEntryPointTest extends TestCase
{
    /**
     * Repository root.
     */
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * The path consumers hardcode, relative to the library root.
     */
    private const ENTRY = '/src/consumer.php';

    public function testEntryPointShipsInsideSrc(): void
    {
        $this->assertFileExists(
            self::root() . self::ENTRY,
            'Consumers require JPATH_LIBRARIES . "/cwmscripture/src/consumer.php" by literal path. '
            . 'It must live in src/, which is the only directory the packager ships — a loose file '
            . 'at the library root is silently left out of the zip.'
        );
    }

    public function testEntryPointIsCoveredByTheManifest(): void
    {
        $xml = simplexml_load_file(self::root() . '/cwmscripture.xml');

        $this->assertNotFalse($xml);

        $shipped = array_map('strval', $xml->xpath('//files/folder') ?: []);

        $this->assertContains(
            'src',
            $shipped,
            'The manifest must ship the src folder, or the entry point never reaches the site.'
        );
    }

    public function testEntryPointReturnsTheDocumentedApi(): void
    {
        if (!\defined('_JEXEC')) {
            \define('_JEXEC', 1);
        }

        $consumer = require self::root() . self::ENTRY;

        $this->assertIsObject($consumer, 'The file must return an object, not bool or null.');

        foreach (['register', 'unregister'] as $method) {
            $this->assertTrue(
                method_exists($consumer, $method),
                "Consumers call ->{$method}() by name; removing it breaks three repositories."
            );
        }
    }

    public function testRegisterAcceptsTheDocumentedSignature(): void
    {
        if (!\defined('_JEXEC')) {
            \define('_JEXEC', 1);
        }

        $consumer = require self::root() . self::ENTRY;

        $register = new \ReflectionMethod($consumer, 'register');
        $names    = array_map(static fn ($p) => $p->getName(), $register->getParameters());

        // Consumers pass `name:` as a named argument, so this is not just ordering.
        $this->assertSame(['element', 'type', 'folder', 'name'], $names);

        $unregister = new \ReflectionMethod($consumer, 'unregister');
        $unnames    = array_map(static fn ($p) => $p->getName(), $unregister->getParameters());

        $this->assertSame(
            ['element', 'type', 'folder'],
            $unnames,
            'unregister() must not gain a $name parameter: callers branch instead of passing one, '
            . 'and a single dynamic call with name: would fatal.'
        );
    }

    public function testEntryPointSurvivesRepeatedRequires(): void
    {
        if (!\defined('_JEXEC')) {
            \define('_JEXEC', 1);
        }

        // A package install requires this once per consumer in a single request.
        $first  = require self::root() . self::ENTRY;
        $second = require self::root() . self::ENTRY;

        $this->assertIsObject($second, 'A second require must still yield the API object.');
        $this->assertNotSame($first, $second, 'Each require returns a fresh instance — no shared state.');
    }

    public function testEntryPointDeclaresNoNamedSymbols(): void
    {
        $source = (string) file_get_contents(self::root() . self::ENTRY);

        // Named symbols would make repeated requires fatal with "already declared",
        // which is exactly the failure mode a package install would hit.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+\w/mi',
            $source,
            'The returned class must stay anonymous.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*function\s+\w/mi',
            $source,
            'No global functions — they would collide on the second require.'
        );
    }
}
