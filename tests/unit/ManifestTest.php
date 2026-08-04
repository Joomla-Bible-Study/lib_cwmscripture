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

    /**
     * Update SQL runs from scratch on every upgrade, so it has to be idempotent.
     *
     * LibraryAdapter's upgrade deletes the #__extensions row and stores a new one
     * (checkExtensionInFilesystem -> uninstall -> storeExtension), and
     * InstallerAdapter::parseQueries() passes that *new* extension_id to
     * parseSchemaUpdates(). No #__schemas row exists for it yet, so Joomla replays
     * every file in sql/updates/mysql, not just the ones newer than the installed
     * version. A bare ALTER TABLE ADD COLUMN would therefore fail on the second
     * upgrade — and take the install down with it, since parseQueries() throws.
     */
    public function testUpdateSqlIsIdempotent(): void
    {
        $files = glob(self::root() . '/sql/updates/mysql/*.sql') ?: [];

        $this->assertNotEmpty($files, 'Expected update SQL files to exist.');

        foreach ($files as $file) {
            $sql  = (string) file_get_contents($file);
            $name = basename($file);

            // Strip comments so prose about DROP/ALTER does not trip the checks.
            $statements = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

            $this->assertSame(
                preg_match_all('/\bCREATE\s+TABLE\b/i', $statements),
                preg_match_all('/\bCREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $statements),
                "{$name}: every CREATE TABLE must be IF NOT EXISTS — this file is replayed on every upgrade."
            );

            $this->assertSame(
                preg_match_all('/\bDROP\s+TABLE\b/i', $statements),
                preg_match_all('/\bDROP\s+TABLE\s+IF\s+EXISTS\b/i', $statements),
                "{$name}: every DROP TABLE must be IF EXISTS."
            );

            $this->assertSame(
                0,
                preg_match_all('/\bALTER\s+TABLE\b(?![^;]*\bIF\s+(?:NOT\s+)?EXISTS\b)/i', $statements),
                "{$name}: a bare ALTER TABLE is not replay-safe. Guard it with IF NOT EXISTS "
                . '(MariaDB) or move the change into script.php, which can check information_schema first.'
            );
        }
    }

    /**
     * ensureTables() must know about every table the install SQL creates.
     *
     * It is the only mechanism that can add a table to a site whose bible tables
     * already exist — Joomla's schema replay is the other, and CLAUDE.md explains
     * why that is not something to lean on. A table added to the install SQL but
     * not to the list would simply never appear on upgraded sites.
     */
    public function testEnsureTablesCoversEveryInstalledTable(): void
    {
        $sql = (string) file_get_contents(self::root() . '/sql/install.mysql.utf8.sql');

        preg_match_all(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`#__(\w+)`/i',
            $sql,
            $matches
        );

        $created = $matches[1] ?? [];

        $this->assertNotEmpty($created, 'Expected the install SQL to create tables.');

        $script = (string) file_get_contents(self::root() . '/script.php');

        // The list is a literal in script.php on purpose: an install script should
        // not depend on the autoloader to know its own schema.
        preg_match(
            '/\$expected\s*=\s*\[(.*?)\];/s',
            $script,
            $listMatch
        );

        $this->assertNotEmpty(
            $listMatch[1] ?? '',
            'Could not find the $expected table list in script.php::ensureTables().'
        );

        preg_match_all("/'(\w+)'/", $listMatch[1], $listed);

        $expected = $listed[1] ?? [];

        sort($created);
        sort($expected);

        $this->assertSame(
            $created,
            $expected,
            'script.php::ensureTables() and the install SQL disagree about which tables exist. '
            . 'A table only in the SQL never reaches upgraded sites; one only in the list is dead weight.'
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
