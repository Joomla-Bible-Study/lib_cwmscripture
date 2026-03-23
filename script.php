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
     * @param   InstallerAdapter  $adapter  The installer adapter
     *
     * @return  bool
     *
     * @since  1.0.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
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

            // Check if the primary table exists
            if (\in_array($prefix . 'bsms_bible_translations', $tables, true)) {
                return;
            }

            // Tables missing — run install SQL
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

            Log::add('lib_cwmscripture: Created missing tables from install SQL', Log::INFO, 'cwmscripture.install');
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
