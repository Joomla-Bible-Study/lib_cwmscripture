<?php

/**
 * Book names must be nameable by this library alone.
 *
 * ScriptureHelper::getBookName() ends in Text::_() on a JBS_BBK_* key. Those
 * keys used to live only in com_proclaim's admin language file, so a book could
 * be named only when something else had loaded a different extension's
 * language — its site dispatcher, its system plugin or its service provider,
 * none of which this library can see or depend on. On a site running
 * ScriptureLinks or Living Word without Proclaim there was nothing to load them
 * at all, and every reference rendered as `JBS_BBK_JOHN 3` (#39).
 *
 * These tests read the INI files directly rather than going through Text::_(),
 * because Text::_() answers for whatever the running application happens to have
 * loaded — which is exactly the coupling under test. A test that asked Text::_()
 * would pass on any machine with Proclaim installed and prove nothing.
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Helper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookNameLanguageTest extends TestCase
{
    /**
     * Repository root.
     */
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * The book keys ScriptureHelper resolves, read from the source so the test
     * cannot drift from the constant it is guarding.
     *
     * @return array<string, int>
     */
    private static function bookKeys(): array
    {
        $src = file_get_contents(self::root() . '/src/Helper/ScriptureHelper.php');

        preg_match('/BOOK_KEYS\s*=\s*\[(.*?)\];/s', $src, $m);
        preg_match_all("/'(JBS_BBK_[A-Z0-9_]+)'\s*=>\s*(\d+)/", $m[1] ?? '', $pairs, PREG_SET_ORDER);

        $keys = [];

        foreach ($pairs as $pair) {
            $keys[$pair[1]] = (int) $pair[2];
        }

        return $keys;
    }

    /**
     * One case per shipped language file.
     *
     * @return array<string, array{0: string}>
     */
    public static function languageFileProvider(): array
    {
        $cases = [];

        foreach (glob(self::root() . '/language/*/*.lib_cwmscripture.ini') ?: [] as $file) {
            $cases[basename(\dirname($file))] = [$file];
        }

        self::assertNotSame([], $cases, 'No language files found — has the directory moved?');

        return $cases;
    }

    public function testEnglishNamesEveryBookThisLibraryKnows(): void
    {
        $keys = self::bookKeys();

        $this->assertCount(73, $keys, '66 canonical books plus 7 deuterocanonical');

        $ini = parse_ini_file(
            self::root() . '/language/en-GB/en-GB.lib_cwmscripture.ini',
            false,
            INI_SCANNER_RAW
        );

        $this->assertIsArray($ini, 'en-GB must parse');

        $missing = array_diff(array_keys($keys), array_keys($ini));

        $this->assertSame(
            [],
            $missing,
            'These book keys have no English string in this library, so getBookName() returns the raw key '
            . 'on any site without com_proclaim: ' . implode(', ', $missing)
        );
    }

    /**
     * A language either names every book or none of them.
     *
     * Half a set is the state that hides: most references render and a few come
     * out as raw keys, which reads like a data problem rather than a missing
     * translation. Untranslated languages fall back to en-GB, which is correct
     * and is why "none" passes.
     *
     * @param   string  $file  The language file under test.
     */
    #[DataProvider('languageFileProvider')]
    public function testEachLanguageIsCompleteOrAbsent(string $file): void
    {
        $keys = array_keys(self::bookKeys());
        $ini  = parse_ini_file($file, false, INI_SCANNER_RAW);

        $this->assertIsArray($ini, basename($file) . ' must parse');

        $present = array_intersect($keys, array_keys($ini));
        $count   = \count($present);

        $this->assertTrue(
            $count === 0 || $count === \count($keys),
            basename($file) . " names {$count} of " . \count($keys)
            . ' books. Add the rest, or remove them all and let en-GB answer.'
        );
    }

    /**
     * The helper must ask for its own language file.
     *
     * Shipping the strings is only half the fix: nothing loads
     * lib_cwmscripture's language on the path getBookName() takes, so without
     * this call the new strings are present and still never read.
     */
    public function testTheHelperLoadsItsOwnLanguage(): void
    {
        $src = file_get_contents(self::root() . '/src/Helper/ScriptureHelper.php');

        $this->assertStringContainsString(
            "load('lib_cwmscripture'",
            $src,
            'ScriptureHelper must load its own language file, or the book strings it ships are never read'
        );

        foreach (['getBookName', 'getAllBooks', 'getTranslatedBookMap'] as $method) {
            $body = self::methodBody($src, $method);

            $this->assertStringContainsString(
                'self::loadLanguage()',
                $body,
                $method . '() translates a book key, so it must ensure the language is loaded first'
            );
        }
    }

    /**
     * Crude but adequate: everything between a method signature and the next one.
     */
    private static function methodBody(string $src, string $method): string
    {
        $start = strpos($src, ' function ' . $method . '(');

        if ($start === false) {
            return '';
        }

        $next = preg_match('/ function \w+\(/', $src, $m, PREG_OFFSET_CAPTURE, $start + 10)
            ? $m[0][1]
            : \strlen($src);

        return substr($src, $start, $next - $start);
    }
}
