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
 * Registering is therefore worth doing, and the supported way is the two-line
 * helper the library installs beside itself — it needs no autoloading and no
 * error handling from the caller:
 *
 *   // postflight
 *   if (is_file($f = JPATH_LIBRARIES . '/cwmscripture/src/consumer.php')) {
 *       (require $f)->register('com_foo', 'component', name: 'Foo');
 *   }
 *
 *   // uninstall
 *   if (is_file($f = JPATH_LIBRARIES . '/cwmscripture/src/consumer.php')) {
 *       (require $f)->unregister('com_foo', 'component');
 *   }
 *
 * Neither call is load-bearing, by design:
 *
 * - Not registering is survivable — {@see ConsumerScanner} finds extensions that
 *   reference this library's namespace on disk, so an author who never read any
 *   of this is still protected.
 * - Not unregistering is survivable — {@see self::installedExcluding()}
 *   cross-checks every entry against `#__extensions` and prunes rows whose
 *   extension is gone, so a stale row cannot pin the tables forever.
 *
 * What registering buys over being detected is a display name in the refusal
 * message, and immunity to the scan's budget limit on very large installs.
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
        ['element' => 'com_livingword', 'type' => 'component', 'folder' => '',        'name' => 'Living Word (com_livingword)'],
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
        return self::installedExcluding([]);
    }

    /**
     * Installed consumers, ignoring the ones the caller is itself removing.
     *
     * This is what a package uninstall script needs: "once my own children are
     * gone, is anything still using the library?" Without it a package would
     * have to hardcode which other extensions might exist, which is exactly the
     * blind spot the registry exists to remove — a registered third party would
     * be missed and its tables dropped.
     *
     * @param   array<int, array{element: string, type: string, folder?: string}>  $exclude  Consumers to ignore
     *
     * @return  string[]  Human-readable names of everything else still installed
     *
     * @throws  \Throwable  When `#__extensions` cannot be queried — callers decide
     *
     * @since  1.1.6
     */
    public static function installedExcluding(array $exclude): array
    {
        $db    = self::db();
        $found = [];

        // Three sources, in ascending order of trust: the hardcoded list, the
        // registry, and the filesystem. The scan is last because it is the only
        // one that cannot be wrong about intent — an extension referencing our
        // namespace is using us whether or not anyone registered it.
        $sources = array_merge(self::FIRST_PARTY, self::registered($db), self::detected());

        foreach ($sources as $consumer) {
            if (!self::isInstalled($db, $consumer)) {
                // Registered but no longer present — drop the stale row.
                if (!\in_array($consumer, self::FIRST_PARTY, true)) {
                    self::unregister($consumer['element'], $consumer['type'], $consumer['folder']);
                }

                continue;
            }

            if (self::matchesAny($consumer, $exclude)) {
                continue;
            }

            $found[] = $consumer['name'];
        }

        return array_values(array_unique($found));
    }

    /**
     * Consumers found on disk, resilient to this class having been hand-loaded.
     *
     * Install scripts routinely reach this class with `require_once` because the
     * namespace is not autoloadable during an install — `pkg_proclaim`'s and
     * `pkg_cwmscripture`'s scripts both do. In that state a plain
     * `ConsumerScanner::detect()` throws "class not found", and the caller's
     * catch-all turns a working scan into a silent no-op. So load the sibling
     * file the same way, and degrade to registry-plus-first-party if it is
     * genuinely absent rather than taking the caller down.
     *
     * @return  array<int, array{element: string, type: string, folder: string, name: string}>
     *
     * @since  1.1.7
     */
    private static function detected(): array
    {
        if (!class_exists(ConsumerScanner::class)) {
            $path = __DIR__ . '/ConsumerScanner.php';

            if (is_file($path)) {
                require_once $path;
            }
        }

        if (!class_exists(ConsumerScanner::class)) {
            return [];
        }

        try {
            return ConsumerScanner::detect();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Does this consumer match any of the given descriptors?
     *
     * @param   array  $consumer  Consumer descriptor
     * @param   array  $list      Descriptors to match against
     *
     * @return  bool
     *
     * @since  1.1.6
     */
    private static function matchesAny(array $consumer, array $list): bool
    {
        foreach ($list as $candidate) {
            if (
                ($candidate['element'] ?? '') === $consumer['element']
                && ($candidate['type'] ?? '') === $consumer['type']
                && ($candidate['folder'] ?? '') === ($consumer['folder'] ?? '')
            ) {
                return true;
            }
        }

        return false;
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
