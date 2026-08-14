<?php

/**
 * Part of CWM Scripture Library
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 */

namespace CWM\Library\Scripture;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Library version information and installation checks.
 *
 * Consumers (Proclaim, plg_content_scripturelinks, etc.) can call these
 * static methods during their install/update scripts to determine whether
 * lib_cwmscripture needs to be installed or upgraded.
 *
 * Usage in a component install script:
 *
 *   use CWM\Library\Scripture\LibraryVersion;
 *
 *   if (!LibraryVersion::isInstalled()) {
 *       // Install the library from embedded ZIP
 *   }
 *
 *   if (LibraryVersion::needsUpgrade('1.2.0')) {
 *       // Current install is older than 1.2.0, upgrade needed
 *   }
 *
 * @since  1.0.0
 */
class LibraryVersion
{
    /**
     * The library version.
     *
     * Written by `cwm-bump` alongside the manifest, via the
     * `versionTracking.sourceFiles` entry in cwm-build.config.json (needs
     * cwm/build-tools >= 1.13). Keeping it in step matters because satisfies()
     * and needsUpgrade() read it: a stale value tells consumers gating on a
     * minimum version that this library is older than it is.
     *
     * Do not edit by hand: bump through `composer bump-version` so the manifest,
     * package.json and this constant move together. tests/unit/VersionDeclarationsTest.php
     * fails if they diverge.
     *
     * @var  string
     * @since  1.0.0
     */
    public const VERSION = '1.1.18';

    /**
     * Check if the library extension is installed in Joomla.
     *
     * @return  bool  True if the library is registered in #__extensions
     *
     * @since  1.0.0
     */
    public static function isInstalled(): bool
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('library'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('cwmscripture'));
            $db->setQuery($query);

            return (int) $db->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the currently installed version of the library.
     *
     * Reads the version from the manifest cache in #__extensions.
     * Returns '0.0.0' if the library is not installed.
     *
     * @return  string  Semver version string
     *
     * @since  1.0.0
     */
    public static function getInstalledVersion(): string
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
     * Check if the installed library is older than a required version.
     *
     * @param   string  $requiredVersion  The minimum version needed (e.g. '1.2.0')
     *
     * @return  bool  True if the installed version is older than $requiredVersion
     *
     * @since  1.0.0
     */
    public static function needsUpgrade(string $requiredVersion): bool
    {
        $installed = self::getInstalledVersion();

        return version_compare($installed, $requiredVersion, '<');
    }

    /**
     * Check if the installed library satisfies a minimum version requirement.
     *
     * Convenience method — the inverse of needsUpgrade().
     *
     * @param   string  $minimumVersion  The minimum acceptable version
     *
     * @return  bool  True if the installed version is >= $minimumVersion
     *
     * @since  1.0.0
     */
    public static function satisfies(string $minimumVersion): bool
    {
        return !self::needsUpgrade($minimumVersion);
    }
}
