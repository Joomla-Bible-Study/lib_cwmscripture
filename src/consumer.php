<?php

/**
 * Consumer registration entry point for lib_cwmscripture.
 *
 * The whole point of this file is that an install script should not have to know
 * anything about this library to declare a dependency on it. Two lines:
 *
 *   if (is_file($f = JPATH_LIBRARIES . '/cwmscripture/src/consumer.php')) {
 *       (require $f)->register('com_foo', 'component', name: 'Foo');
 *   }
 *
 * and on uninstall:
 *
 *   if (is_file($f = JPATH_LIBRARIES . '/cwmscripture/src/consumer.php')) {
 *       (require $f)->unregister('com_foo', 'component');
 *   }
 *
 * Everything the caller would otherwise have to write lives here instead:
 *
 * - **Autoloading.** `CWM\Library\Scripture\*` is routinely unresolvable inside
 *   an install script. Joomla builds its PSR-4 map at request start, and during a
 *   package install this library lands in the same request, one step ahead of the
 *   extension registering — so the map predates us. This file registers the
 *   namespace from its own directory, which it knows absolutely.
 * - **Version tolerance.** Older libraries have no registry at all. The call
 *   no-ops instead of exploding.
 * - **Error handling.** Registry bookkeeping must never fail somebody's install,
 *   so nothing here throws.
 *
 * The `is_file()` guard is the caller's only responsibility, and it is there for
 * the case where the library genuinely is not installed.
 *
 * **Use `require`, not `require_once`.** A package install runs several
 * consumers in one request — library, then each plugin, then the component — and
 * `require_once` returns `true` rather than this object on every call after the
 * first, so the second consumer would fatal on `true->register()`. This file
 * declares no named symbols (the returned class is anonymous), so requiring it
 * repeatedly is free and safe.
 *
 * It lives in `src/` rather than the library root because the packager ships
 * whole directories only — `src` is already mapped, a loose root file would need
 * a build-tools change to be included at all, and silently shipping without this
 * file would break every consumer's registration. Do not "tidy" it out of here.
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 *
 * @since      1.1.7
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

return new class () {
    /**
     * Declare an extension as a consumer of this library.
     *
     * Call from postflight, which runs on install and update alike; the row is
     * keyed on element/type/folder, so repeat calls just refresh it.
     *
     * @param   string  $element  Extension element, e.g. `com_foo`, or the plugin element
     * @param   string  $type     component, plugin, module or library
     * @param   string  $folder   Plugin group — required for plugins, part of the key
     * @param   string  $name     Name shown to an administrator when an uninstall is refused
     *
     * @return  bool  True when registered; false when the library is too old or the write failed
     *
     * @since  1.1.7
     */
    public function register(
        string $element,
        string $type = 'component',
        string $folder = '',
        string $name = ''
    ): bool {
        $registry = $this->registry();

        return $registry === null ? false : $registry::register($element, $type, $folder, $name);
    }

    /**
     * Drop an extension's row from the registry.
     *
     * Good manners rather than a requirement — stale rows are pruned when the
     * library walks the registry and finds no matching `#__extensions` row.
     *
     * @param   string  $element  Extension element
     * @param   string  $type     component, plugin, module or library
     * @param   string  $folder   Plugin group — required for plugins, part of the key
     *
     * @return  bool  True when the row is gone
     *
     * @since  1.1.7
     */
    public function unregister(string $element, string $type = 'component', string $folder = ''): bool
    {
        $registry = $this->registry();

        return $registry === null ? false : $registry::unregister($element, $type, $folder);
    }

    /**
     * Resolve ConsumerRegistry, registering the namespace if Joomla's autoloader
     * has not caught up with this library yet.
     *
     * @return  class-string|null  The class name, or null when unavailable
     *
     * @since  1.1.7
     */
    private function registry(): ?string
    {
        $class = 'CWM\\Library\\Scripture\\Installer\\ConsumerRegistry';

        if (class_exists($class, false)) {
            return $class;
        }

        try {
            if (class_exists('JLoader')) {
                \JLoader::registerNamespace('CWM\\Library\\Scripture', __DIR__, false, false, 'psr4');
            }

            // Last resort: this file sits beside the class, so load it directly
            // rather than depending on any autoloader agreeing with us.
            if (!class_exists($class) && is_file(__DIR__ . '/Installer/ConsumerRegistry.php')) {
                require_once __DIR__ . '/Installer/ConsumerRegistry.php';
            }

            return class_exists($class) ? $class : null;
        } catch (\Throwable) {
            return null;
        }
    }
};
