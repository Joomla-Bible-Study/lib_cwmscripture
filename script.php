<?php

/**
 * CWM Scripture Library - Installation Script
 *
 * Handles install, update, and uninstall lifecycle events.
 * Joomla calls this automatically via the <scriptfile> manifest entry.
 *
 * During install/update:
 *   - Checks if the currently installed version is already up to date (skips if so)
 *   - Seeds the GetBible translation catalog
 *   - Registers the Joomla logger
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use CWM\Library\Scripture\Installer\ConsumerRegistry;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

return new class () implements InstallerScriptInterface {
    /**
     * Minimum PHP version required.
     *
     * @var  string
     * @since  1.0.0
     */
    private string $minimumPhp = '8.3.0';

    /**
     * Minimum Joomla version required.
     *
     * @var  string
     * @since  1.0.0
     */
    private string $minimumJoomla = '5.2.0';

    /**
     * Called before install/update to check requirements.
     *
     * @param   string            $type     Install type (install, update, discover_install)
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  bool  True to proceed, false to abort
     *
     * @since  1.0.0
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall' && !$this->allowUninstall($adapter)) {
            return false;
        }

        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Log::add(
                'lib_cwmscripture requires PHP ' . $this->minimumPhp . '+. Found: ' . PHP_VERSION,
                Log::ERROR,
                'jerror'
            );

            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Log::add(
                'lib_cwmscripture requires Joomla ' . $this->minimumJoomla . '+. Found: ' . JVERSION,
                Log::ERROR,
                'jerror'
            );

            return false;
        }

        // For updates: check if the installed version is already current
        if ($type === 'update') {
            $installedVersion = $this->getInstalledVersion();
            $newVersion       = (string) $adapter->getManifest()->version;

            if (version_compare($installedVersion, $newVersion, '>=')) {
                Log::add(
                    'lib_cwmscripture v' . $installedVersion . ' is already installed (>= ' . $newVersion . '). Skipping.',
                    Log::INFO,
                    'cwmscripture.install'
                );

                // Return true to let Joomla finish cleanly, but postflight will be a no-op
                return true;
            }
        }

        return true;
    }

    /**
     * Called after install/update completes.
     *
     * @param   string            $type     Install type
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  bool
     *
     * @since  1.0.0
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        // Rebuild the namespace map so the library's CWM\Library\Scripture
        // namespace is available immediately.  When installed as part of
        // pkg_proclaim, the component installs next and needs this namespace.
        $cacheFile = JPATH_ADMINISTRATOR . '/cache/autoload_psr4.php';

        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        // Ensure tables exist — on "update" installs (discover install or
        // re-install over existing extension record), Joomla skips the
        // install SQL and only runs update SQL, which may be empty.
        $this->ensureTables($adapter);

        // Seed the GetBible translation catalog if needed
        try {
            $this->seedTranslationCatalog();
        } catch (\Throwable $e) {
            Log::add(
                'lib_cwmscripture: Failed to seed translation catalog: ' . $e->getMessage(),
                Log::WARNING,
                'cwmscripture.install'
            );
        }

        // Core translation downloads are deferred to a one-shot Joomla task
        // that plg_task_cwmscripture schedules in its own postflight — see
        // plg_task_cwmscripture/script.php. Doing them inline here adds roughly
        // 40 seconds to every install.

        $version = (string) $adapter->getManifest()->version;
        Log::add(
            'lib_cwmscripture v' . $version . ' ' . $type . ' completed successfully.',
            Log::INFO,
            'cwmscripture.install'
        );

        return true;
    }

    /**
     * Called on install.
     *
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  bool
     *
     * @since  1.0.0
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Called on update.
     *
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  bool
     *
     * @since  1.0.0
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Called on uninstall.
     *
     * The bible tables are NOT removed by manifest SQL, and cwmscripture.xml
     * carries no <uninstall> block for that reason: Joomla's LibraryAdapter
     * uninstalls the existing library on every update, so manifest uninstall
     * SQL runs on upgrades too and would wipe every downloaded translation.
     *
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  bool
     *
     * @since  1.0.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        $this->dropTablesIfOrphaned($adapter);

        return true;
    }

    /**
     * Drop the bible tables, but only when this is a genuine, final removal.
     *
     * Two guards, both of which must pass:
     *
     *   1. The installer must not be in a package/upgrade uninstall.
     *      LibraryAdapter::checkExtensionInFilesystem() uninstalls the current
     *      library before installing the new one and flags that inner
     *      Installer with setPackageUninstall(true) — the upgrade path that
     *      would otherwise destroy people's downloaded Bibles.
     *   2. No extension that shares these tables may still be installed. The
     *      three #__bsms_bible_* / #__bsms_scripture_cache tables are shared
     *      with com_proclaim and plg_content_scripturelinks; removing the
     *      library while either is present must leave their data alone.
     *
     * The conservative outcome is orphaned tables, which cost disk but nothing
     * else, and which a later standalone uninstall cleans up.
     *
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  void
     *
     * @since  1.1.6
     */
    private function dropTablesIfOrphaned(InstallerAdapter $adapter): void
    {
        try {
            if ($adapter->getParent()->isPackageUninstall()) {
                Log::add(
                    'lib_cwmscripture: package/upgrade uninstall — keeping the bible tables.',
                    Log::INFO,
                    'cwmscripture.install'
                );

                return;
            }

            $consumers = $this->getInstalledConsumers();

            if ($consumers !== []) {
                Log::add(
                    'lib_cwmscripture: scripture tables are still shared with an installed '
                    . 'extension (' . implode(', ', $consumers) . ') — keeping them.',
                    Log::INFO,
                    'cwmscripture.install'
                );

                return;
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $tables = [
                '#__bsms_scripture_consumers',
                '#__bsms_scripture_cache',
                '#__bsms_bible_verses',
                '#__bsms_bible_translations',
            ];

            foreach ($tables as $table) {
                $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table));
                $db->execute();
            }

            Log::add(
                'lib_cwmscripture: removed the scripture tables (no consumer left).',
                Log::INFO,
                'cwmscripture.install'
            );
        } catch (\Throwable $e) {
            Log::add(
                'lib_cwmscripture: table cleanup skipped — ' . $e->getMessage(),
                Log::WARNING,
                'cwmscripture.install'
            );
        }
    }

    /**
     * List the installed extensions that depend on this library.
     *
     * These consume the `CWM\Library\Scripture` classes and share the
     * `#__bsms_bible_*` / `#__bsms_scripture_cache` tables. Removing the library
     * while any of them is present leaves them calling classes that no longer
     * exist, so this drives both the uninstall guard in preflight() and the
     * table-retention check in dropTablesIfOrphaned().
     *
     * Delegates to ConsumerRegistry, which merges the first-party extensions
     * with third parties that registered themselves. Joomla tracks no library
     * dependencies of its own, so an unregistered third party is invisible here
     * — that is what the registry exists to fix.
     *
     * The class is required directly when the autoloader has not picked up the
     * library namespace yet. If it cannot be loaded at all we fall back to the
     * first-party list, which is worse but never wrong about Proclaim.
     *
     * @return  string[]  Human-readable names; empty when nothing depends on the library
     *
     * @throws  \Throwable  When #__extensions cannot be queried — callers decide
     *
     * @since  1.1.6
     */
    private function getInstalledConsumers(): array
    {
        if (!class_exists(ConsumerRegistry::class)) {
            $path = JPATH_LIBRARIES . '/cwmscripture/src/Installer/ConsumerRegistry.php';

            if (is_file($path)) {
                require_once $path;
            }
        }

        if (class_exists(ConsumerRegistry::class)) {
            return ConsumerRegistry::installed();
        }

        Log::add(
            'lib_cwmscripture: ConsumerRegistry unavailable — falling back to the first-party list, '
            . 'third-party consumers will not be seen.',
            Log::WARNING,
            'cwmscripture.install'
        );

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $found = [];

        $known = [
            ['type' => 'component', 'element' => 'com_proclaim',   'label' => 'Proclaim (com_proclaim)'],
            ['type' => 'plugin',    'element' => 'scripturelinks', 'label' => 'Scripture Links (plg_content_scripturelinks)'],
            ['type' => 'plugin',    'element' => 'cwmscripture',   'label' => 'Scripture task plugin (plg_task_cwmscripture)'],
        ];

        foreach ($known as $consumer) {
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote($consumer['type']))
                ->where($db->quoteName('element') . ' = ' . $db->quote($consumer['element']));
            $db->setQuery($query);

            if ((int) $db->loadResult() > 0) {
                $found[] = $consumer['label'];
            }
        }

        return $found;
    }

    /**
     * Refuse a standalone uninstall while something still depends on the library.
     *
     * Returning false from preflight aborts the uninstall: InstallerAdapter
     * wraps triggerManifestScript('preflight') in a try/catch and bails on the
     * RuntimeException it throws for a false return.
     *
     * The `isPackageUninstall()` check is essential, not cosmetic. Joomla
     * implements a library update as uninstall-then-install, so preflight fires
     * with $type === 'uninstall' on every upgrade too; without the check this
     * guard would make the library permanently un-updatable, since Proclaim is
     * always installed. The upgrade cycle and genuine package removals both set
     * that flag, so both are allowed through.
     *
     * Fails open: if #__extensions cannot be read we allow the uninstall rather
     * than trap the administrator. The irreplaceable thing — downloaded
     * translations — is protected separately by dropTablesIfOrphaned(); files
     * can always be reinstalled.
     *
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  bool  False to abort the uninstall
     *
     * @since  1.1.6
     */
    private function allowUninstall(InstallerAdapter $adapter): bool
    {
        try {
            if ($adapter->getParent()->isPackageUninstall()) {
                return true;
            }

            $consumers = $this->getInstalledConsumers();

            if ($consumers === []) {
                return true;
            }

            Log::add(
                'lib_cwmscripture cannot be uninstalled: it is still required by '
                . implode(', ', $consumers)
                . '. Uninstall those first, then remove this library.',
                Log::ERROR,
                'jerror'
            );

            return false;
        } catch (\Throwable $e) {
            Log::add(
                'lib_cwmscripture: dependency check skipped — ' . $e->getMessage(),
                Log::WARNING,
                'cwmscripture.install'
            );

            return true;
        }
    }

    /**
     * Get the currently installed version from #__extensions manifest_cache.
     *
     * @return  string  Version string or '0.0.0' if not found
     *
     * @since  1.0.0
     */
    private function getInstalledVersion(): string
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('library'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('cwmscripture'));
            $db->setQuery($query);
            $cache = $db->loadResult();

            if (!$cache) {
                return '0.0.0';
            }

            $manifest = json_decode($cache, true);

            return $manifest['version'] ?? '0.0.0';
        } catch (\Throwable) {
            return '0.0.0';
        }
    }

    /**
     * Ensure the library's tables exist by running the install SQL if needed.
     *
     * When the library is re-installed over an existing #__extensions record
     * (e.g., from a discover install by Proclaim), Joomla treats it as an
     * "update" and skips the install SQL. This method detects missing tables
     * and runs the install SQL manually.
     *
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  void
     *
     * @since  1.0.0
     */
    private function ensureTables(InstallerAdapter $adapter): void
    {
        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $tables = $db->getTableList();
            $prefix = $db->getPrefix();

            // Every table the install SQL creates, checked individually rather
            // than through a single sentinel. Returning as soon as
            // bsms_bible_translations exists would mean a table introduced in a
            // later version is never created here: #__bsms_scripture_consumers
            // (new in 1.1.6) would then reach upgraded sites only because Joomla
            // replays the schema updates, and the uninstall guard's own storage
            // must not depend on that — see the "Update SQL is replayed" note in
            // CLAUDE.md. ManifestTest keeps this list in step with the install SQL.
            $expected = [
                'bsms_bible_translations',
                'bsms_bible_verses',
                'bsms_scripture_cache',
                'bsms_scripture_consumers',
            ];

            $missing = [];

            foreach ($expected as $table) {
                if (!\in_array($prefix . $table, $tables, true)) {
                    $missing[] = $table;
                }
            }

            if ($missing === []) {
                return;
            }

            // Something is missing — replay the install SQL. It is entirely
            // CREATE TABLE IF NOT EXISTS / INSERT IGNORE, so the tables that do
            // exist are untouched and their rows survive.
            $sqlFile = $adapter->getParent()->getPath('source') . '/lib_cwmscripture/sql/install.mysql.utf8.sql';

            if (!file_exists($sqlFile)) {
                // Try alternative path (already installed location)
                $sqlFile = JPATH_LIBRARIES . '/cwmscripture/sql/install.mysql.utf8.sql';
            }

            if (!file_exists($sqlFile)) {
                Log::add('lib_cwmscripture: install SQL not found, tables not created', Log::WARNING, 'cwmscripture.install');

                return;
            }

            $sql = file_get_contents($sqlFile);

            // Replace #__ prefix
            $sql = str_replace('#__', $prefix, $sql);

            // Split and execute statements
            $statements = $db->splitSql($sql);

            foreach ($statements as $statement) {
                $statement = trim($statement);

                if ($statement === '') {
                    continue;
                }

                try {
                    $db->setQuery($statement);
                    $db->execute();
                } catch (\Exception $e) {
                    // CREATE TABLE IF NOT EXISTS is safe to fail
                    Log::add('lib_cwmscripture SQL: ' . $e->getMessage(), Log::WARNING, 'cwmscripture.install');
                }
            }

            Log::add(
                'lib_cwmscripture: replayed install SQL for missing table(s): ' . implode(', ', $missing),
                Log::INFO,
                'cwmscripture.install'
            );
        } catch (\Throwable $e) {
            Log::add('lib_cwmscripture: ensureTables failed: ' . $e->getMessage(), Log::WARNING, 'cwmscripture.install');
        }
    }

    /**
     * Seed the GetBible translation catalog if the table exists but is empty.
     *
     * @return  void
     *
     * @since  1.0.0
     */
    private function seedTranslationCatalog(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Check if the table exists
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();

        if (!\in_array($prefix . 'bsms_bible_translations', $tables, true)) {
            return;
        }

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__bsms_bible_translations'))
            ->where($db->quoteName('source') . ' = ' . $db->quote('getbible'));
        $db->setQuery($query);
        $existing = (int) $db->loadResult();

        // If catalog is already populated, skip
        if ($existing >= 10) {
            return;
        }

        // Use the importer's seed method if the class is available
        if (class_exists(\CWM\Library\Scripture\Importer\BibleImporter::class)) {
            \CWM\Library\Scripture\Importer\BibleImporter::seedGetBibleCatalog();
        }
    }
};
