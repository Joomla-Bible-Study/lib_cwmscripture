<?php

/**
 * Part of CWM Scripture Library
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 */

namespace CWM\Library\Scripture\Installer;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds extensions that use this library but never registered as consumers.
 *
 * {@see ConsumerRegistry} only knows what it is told, so it protects a
 * third-party extension exactly as well as that extension's author read the
 * docs. The case this exists for is real: CWMLivingWord shipped consuming
 * lib_cwmscripture, appeared in neither the registry nor the first-party list,
 * and would have had its downloaded translations dropped by a library uninstall.
 *
 * So instead of trusting registration, look at the disk. Any extension whose PHP
 * references the `CWM\Library\Scripture` namespace is using this library, by
 * definition — it cannot resolve those classes otherwise. That makes a
 * filesystem scan a more reliable signal than a registry row, and it costs the
 * author nothing.
 *
 * The scan is deliberately confined to the uninstall guard. It reads a few
 * thousand files, which is fine for a one-off interactive action and would not
 * be fine per request.
 *
 * @since  __DEPLOY_VERSION__
 */
class ConsumerScanner
{
    /**
     * Namespace that proves consumption. An extension referencing it must be
     * loading classes from this library.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const NEEDLE = 'CWM\\Library\\Scripture';

    /**
     * Upper bound on files read, so a pathological install cannot hang an
     * uninstall. Generous: a large Joomla site is a few thousand PHP files.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const MAX_FILES = 25000;

    /**
     * Directory names never worth searching — third-party dependency trees and
     * build output, none of which identify a Joomla extension.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const SKIP_DIRS = ['node_modules', 'vendor', '.git', 'cache', 'tmp', 'logs'];

    /**
     * Extension roots to walk, as [glob pattern, type, folder-comes-from-path].
     *
     * @return  array<int, array{glob: string, type: string, grouped: bool}>
     *
     * @since  __DEPLOY_VERSION__
     */
    private static function roots(): array
    {
        return [
            ['glob' => JPATH_ADMINISTRATOR . '/components/com_*', 'type' => 'component', 'grouped' => false],
            ['glob' => JPATH_SITE . '/components/com_*',          'type' => 'component', 'grouped' => false],
            ['glob' => JPATH_ADMINISTRATOR . '/modules/mod_*',    'type' => 'module',    'grouped' => false],
            ['glob' => JPATH_SITE . '/modules/mod_*',             'type' => 'module',    'grouped' => false],
            ['glob' => JPATH_PLUGINS . '/*/*',                    'type' => 'plugin',    'grouped' => true],
        ];
    }

    /**
     * Extensions on disk that reference this library.
     *
     * Returns descriptors in {@see ConsumerRegistry} shape so the two sources
     * merge without translation. Whether they are still in `#__extensions` is the
     * registry's business, not ours — a directory can outlive its extension row.
     *
     * Components are reported once even though admin and site both have a
     * directory: the element is the same, and duplicates collapse on the
     * element/type/folder key.
     *
     * @return  array<int, array{element: string, type: string, folder: string, name: string}>
     *
     * @since  __DEPLOY_VERSION__
     */
    public static function detect(): array
    {
        $found  = [];
        $budget = self::MAX_FILES;

        foreach (self::roots() as $root) {
            foreach ((array) glob($root['glob'], GLOB_ONLYDIR) as $dir) {
                if ($budget <= 0) {
                    break 2;
                }

                $element = basename($dir);
                $folder  = $root['grouped'] ? basename(\dirname($dir)) : '';

                // Our own files reference the namespace by definition.
                if ($root['type'] === 'plugin' && $folder === 'system' && $element === 'cwmscripture') {
                    continue;
                }

                $key = $element . '|' . $root['type'] . '|' . $folder;

                if (isset($found[$key])) {
                    continue;
                }

                if (!self::references($dir, $budget)) {
                    continue;
                }

                $found[$key] = [
                    'element' => $element,
                    'type'    => $root['type'],
                    'folder'  => $folder,
                    'name'    => self::label($element, $root['type'], $folder),
                ];
            }
        }

        return array_values($found);
    }

    /**
     * Does any PHP file under this directory reference the library namespace?
     *
     * Stops at the first hit. Decrements the shared budget by reference so one
     * huge extension cannot starve the rest of the scan silently — it just ends
     * the scan, which the caller treats as "no more evidence", the same
     * conservative direction as an unregistered consumer.
     *
     * @param   string  $dir     Directory to search
     * @param   int     $budget  Remaining file allowance, decremented in place
     *
     * @return  bool
     *
     * @since  __DEPLOY_VERSION__
     */
    private static function references(string $dir, int &$budget): bool
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $current): bool {
                        if ($current->isDir()) {
                            return !\in_array($current->getFilename(), self::SKIP_DIRS, true);
                        }

                        return strtolower($current->getExtension()) === 'php';
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($budget-- <= 0) {
                    return false;
                }

                $contents = @file_get_contents($file->getPathname());

                if ($contents !== false && str_contains($contents, self::NEEDLE)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Unreadable tree — treat as no evidence.
            return false;
        }

        return false;
    }

    /**
     * Human-readable label for a detected extension, matching the shape of the
     * names in the first-party list.
     *
     * @param   string  $element  Extension element
     * @param   string  $type     Extension type
     * @param   string  $folder   Plugin group, empty for non-plugins
     *
     * @return  string
     *
     * @since  __DEPLOY_VERSION__
     */
    private static function label(string $element, string $type, string $folder): string
    {
        if ($type === 'plugin') {
            return 'plg_' . $folder . '_' . $element . ' (detected)';
        }

        return $element . ' (detected)';
    }
}
