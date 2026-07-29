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

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Registry of extensions that depend on this library.
 *
 * Joomla has no dependency tracking for libraries: nothing records that an
 * extension needs lib_cwmscripture, and `blockChildUninstall` only covers
 * extensions shipped inside the same package. Without a registry the library's
 * uninstall guard can only recognise the first-party extensions it hardcodes,
 * so a third-party consumer is invisible — the uninstall would be allowed and,
 * worse, the shared `#__bsms_*` tables would be judged orphaned and dropped,
 * destroying downloaded translations the third party still needs.
 *
 * Any extension using the `CWM\Library\Scripture` classes should therefore
 * register itself from its install script:
 *
 *   use CWM\Library\Scripture\Installer\ConsumerRegistry;
 *
 *   public function postflight(string $type, InstallerAdapter $adapter): bool
 *   {
 *       ConsumerRegistry::register('com_foo', 'component', name: 'Foo');
 *
 *       return true;
 *   }
 *
 *   public function uninstall(InstallerAdapter $adapter): bool
 *   {
 *       ConsumerRegistry::unregister('com_foo', 'component');
 *
 *       return true;
 *   }
 *
 * Unregistering is good manners but not load-bearing: {@see self::installed()}
 * cross-checks every entry against `#__extensions` and prunes rows whose
 * extension is gone, so a consumer that never unregisters cannot pin the tables
 * forever.
 *
 * @since  1.1.6
 */
class ConsumerRegistry
{
    /**
     * The registry table.
     *
     * @since  1.1.6
     */
    private const TABLE = '#__bsms_scripture_consumers';

    /**
     * Extensions recognised without registering.
     *
     * Kept here rather than in the install script so there is a single list.
     *
     * @since  1.1.6
     */
    public const FIRST_PARTY = [
        ['element' => 'com_proclaim',   'type' => 'component', 'folder' => '',        'name' => 'Proclaim (com_proclaim)'],
        ['element' => 'scripturelinks', 'type' => 'plugin',    'folder' => 'content', 'name' => 'Scripture Links (plg_content_scripturelinks)'],
        ['element' => 'cwmscripture',   'type' => 'plugin',    'folder' => 'task',    'name' => 'Scripture task plugin (plg_task_cwmscripture)'],
    ];

    /**
     * Register an extension as a consumer of this library.
     *
     * Safe to call on every install and update — the row is keyed on
     * element/type/folder and re-registering simply refreshes it.
     *
     * @param   string  $element  Extension element, e.g. `com_foo`
     * @param   string  $type     Joomla extension type: component, plugin, module, library
     * @param   string  $folder   Plugin group; empty for non-plugins
     * @param   string  $name     Human-readable name shown when an uninstall is refused
     *
     * @return  bool  True when the consumer is registered
     *
     * @since  1.1.6
     */
    public static function register(
        string $element,
        string $type = 'component',
        string $folder = '',
        string $name = ''
    ): bool {
        try {
            $db = self::db();

            self::unregister($element, $type, $folder);

            $columns = ['element', 'type', 'folder', 'name', 'registered'];
            $values  = [
                $db->quote($element),
                $db->quote($type),
                $db->quote($folder),
                $db->quote($name !== '' ? $name : $element),
                $db->quote(Factory::getDate()->toSql()),
            ];

            $query = $db->getQuery(true)
                ->insert($db->quoteName(self::TABLE))
                ->columns($db->quoteName($columns))
                ->values(implode(',', $values));
            $db->setQuery($query);
            $db->execute();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Remove an extension from the registry.
     *
     * @param   string  $element  Extension element
     * @param   string  $type     Joomla extension type
     * @param   string  $folder   Plugin group; empty for non-plugins
     *
     * @return  bool  True when the row is gone
     *
     * @since  1.1.6
     */
    public static function unregister(string $element, string $type = 'component', string $folder = ''): bool
    {
        try {
            $db    = self::db();
            $query = $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element))
                ->where($db->quoteName('type') . ' = ' . $db->quote($type))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
            $db->setQuery($query);
            $db->execute();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * All consumers that are currently installed.
     *
     * Merges the first-party list with the registry, then keeps only entries
     * that still exist in `#__extensions`. Stale registry rows — a consumer that
     * was removed without unregistering — are pruned as a side effect, so they
     * cannot block an uninstall or pin the tables indefinitely.
     *
     * @return  string[]  Human-readable names, empty when nothing depends on the library
     *
     * @throws  \Throwable  When `#__extensions` cannot be queried — callers decide
     *
     * @since  1.1.6
     */
    public static function installed(): array
    {
        $db    = self::db();
        $found = [];

        foreach (array_merge(self::FIRST_PARTY, self::registered($db)) as $consumer) {
            if (self::isInstalled($db, $consumer)) {
                $found[] = $consumer['name'];

                continue;
            }

            // Registered but no longer present — drop the stale row.
            if (!\in_array($consumer, self::FIRST_PARTY, true)) {
                self::unregister($consumer['element'], $consumer['type'], $consumer['folder']);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Read the registry table.
     *
     * Returns an empty list when the table does not exist, which is the case on
     * installs predating 1.1.6 that have not run the schema update yet.
     *
     * @param   DatabaseInterface  $db  The database driver
     *
     * @return  array<int, array{element: string, type: string, folder: string, name: string}>
     *
     * @since  1.1.6
     */
    private static function registered(DatabaseInterface $db): array
    {
        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['element', 'type', 'folder', 'name']))
                ->from($db->quoteName(self::TABLE));
            $db->setQuery($query);

            return $db->loadAssocList() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Is this consumer present in `#__extensions`?
     *
     * @param   DatabaseInterface  $db        The database driver
     * @param   array              $consumer  Consumer descriptor
     *
     * @return  bool
     *
     * @since  1.1.6
     */
    private static function isInstalled(DatabaseInterface $db, array $consumer): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote($consumer['type']))
            ->where($db->quoteName('element') . ' = ' . $db->quote($consumer['element']));

        if (($consumer['folder'] ?? '') !== '') {
            $query->where($db->quoteName('folder') . ' = ' . $db->quote($consumer['folder']));
        }

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * Get the database driver.
     *
     * @return  DatabaseInterface
     *
     * @since  1.1.6
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
