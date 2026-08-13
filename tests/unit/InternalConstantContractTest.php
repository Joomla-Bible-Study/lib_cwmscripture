<?php

/**
 * Contract test binding self::CONST references to constants that exist
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Every `self::NAME` in this library must name a constant that resolves.
 *
 * A class constant is not checked until the line reading it runs, so deleting
 * one leaves a class that loads, instantiates and passes every test that does
 * not enter the branch. `HTTP_MAX_RETRIES` was removed by #50 and only surfaced
 * on the network path — a site with a warm scripture cache never reaches it, so
 * it took a clean install to fail (#64). That is the same shape as the methods
 * and properties the same refactor removed, and the reason those are asserted
 * in ConsumerApiContractTest.
 *
 * The check resolves against ReflectionClass::getConstants() rather than
 * scanning source for `const NAME`. A regex cannot see PHP 8.3 typed constants
 * — `private const array BOOK_KEYS` — so it reports live constants as missing
 * and would have had to be silenced with exactly the blind spot this guards.
 *
 * @since  1.1.17
 */
class InternalConstantContractTest extends TestCase
{
    /**
     * Directory holding the library's classes.
     */
    private const string SRC_DIR = __DIR__ . '/../../src';

    /**
     * One case per class that reads a constant off itself.
     *
     * @return  array<string, array{0: string, 1: string[]}>
     */
    public static function constantReferenceProvider(): array
    {
        $cases = [];

        foreach (self::sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match('/^\s*(?:abstract |final )?class\s+(\w+)/m', $source, $name)) {
                continue;
            }

            if (!preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
                continue;
            }

            preg_match_all('/(?:self|static)::([A-Z][A-Z0-9_]*)\b(?!\s*\()/', $source, $used);

            $referenced = array_values(array_unique($used[1]));

            if ($referenced === []) {
                continue;
            }

            $class = trim($namespace[1]) . '\\' . $name[1];

            $cases[$name[1]] = [$class, $referenced];
        }

        return $cases;
    }

    /**
     * Absolute paths of every PHP file under src/.
     *
     * @return  string[]
     */
    private static function sourceFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC_DIR, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A provider that silently matched nothing would pass every case below.
     */
    #[TestDox('the library source actually parses into cases')]
    public function testTheProviderFoundSomething(): void
    {
        $cases = self::constantReferenceProvider();

        self::assertNotEmpty($cases, 'No self::CONST references were found, so this contract checks nothing.');

        self::assertArrayHasKey(
            'AbstractBibleProvider',
            $cases,
            'AbstractBibleProvider is expected to read constants off itself; the scan is not seeing it.'
        );
    }

    /**
     * @param  string    $class       Fully-qualified class name
     * @param  string[]  $referenced  Constant names the class reads off itself
     */
    #[DataProvider('constantReferenceProvider')]
    #[TestDox('every constant $class reads off itself resolves')]
    public function testReferencedConstantsExist(string $class, array $referenced): void
    {
        self::assertTrue(class_exists($class), $class . ' could not be loaded.');

        $declared = array_keys((new \ReflectionClass($class))->getConstants());

        foreach ($referenced as $constant) {
            self::assertContains(
                $constant,
                $declared,
                $class . ' reads self::' . $constant . ', which no longer exists. PHP does not check a class '
                . 'constant until the line reading it runs, so this class still loads and every test that '
                . 'avoids that branch still passes — it fails at runtime instead, and only on the path that '
                . 'reads it.'
            );
        }
    }
}
