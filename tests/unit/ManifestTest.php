<?php

/**
 * Manifest guards.
 *
 * Joomla's LibraryAdapter uninstalls the installed library before it writes a
 * new one, so anything in <uninstall><sql> runs on every UPDATE. When that
 * pointed at DROP TABLE statements, each upgrade destroyed every locally
 * downloaded Bible translation. These tests keep it from coming back.
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests;

use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    /**
     * Repository root.
     */
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testManifestDeclaresNoUninstallSql(): void
    {
        $xml = simplexml_load_file(self::root() . '/cwmscripture.xml');

        $this->assertNotFalse($xml, 'cwmscripture.xml must be valid XML');
        $this->assertCount(
            0,
            $xml->xpath('//uninstall/sql') ?: [],
            'The manifest must not declare uninstall SQL: LibraryAdapter runs it on every update, '
            . 'which wipes #__bsms_bible_verses. Drop tables from script.php::uninstall() instead.'
        );
    }

    public function testUninstallSqlFileIsANoOp(): void
    {
        $file = self::root() . '/sql/uninstall.mysql.utf8.sql';

        $this->assertFileExists(
            $file,
            'Keep the file: sites carrying an older manifest still reference it by path.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(?!--)\S.*\bDROP\s+TABLE\b/im',
            (string) file_get_contents($file),
            'uninstall.mysql.utf8.sql must stay statement-free — an older manifest on disk still '
            . 'executes this file during the upgrade uninstall.'
        );
    }

    public function testInstallSqlIsRerunnable(): void
    {
        $sql = (string) file_get_contents(self::root() . '/sql/install.mysql.utf8.sql');

        $this->assertSame(
            preg_match_all('/\bCREATE\s+TABLE\b/i', $sql),
            preg_match_all('/\bCREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $sql),
            'Every CREATE TABLE must be IF NOT EXISTS — the install SQL is replayed by '
            . 'script.php::ensureTables() on existing sites.'
        );

        $this->assertSame(
            0,
            preg_match_all('/\bINSERT\s+(?!IGNORE\b)/i', $sql),
            'Catalog seeding must be INSERT IGNORE so a replay cannot clobber user rows.'
        );
    }
}