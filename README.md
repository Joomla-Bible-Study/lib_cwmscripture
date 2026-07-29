# lib_cwmscripture

[![CI](https://github.com/Joomla-Bible-Study/lib_cwmscripture/actions/workflows/ci.yml/badge.svg)](https://github.com/Joomla-Bible-Study/lib_cwmscripture/actions/workflows/ci.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)

A Joomla 5.2+ library extension that provides Bible scripture retrieval, parsing, rendering, and translation management. It serves as the shared scripture engine for the [CWM Proclaim](https://www.christianwebministries.org) component and the `plg_content_scripturelinks` plugin.

## Requirements

- Joomla 5.2+
- PHP 8.3+
- MySQL 5.7+ / MariaDB 10.2+ (InnoDB, utf8mb4)
- Node.js 24+ / npm 11+ (for building frontend assets)

## Installation

Install as a Joomla library extension via the Extension Manager or CLI:

```bash
php cli/joomla.php extension:install --path=/path/to/lib_cwmscripture
```

The installer creates three database tables (safe to run alongside an existing Proclaim install):

| Table | Purpose |
|-------|---------|
| `#__bsms_bible_translations` | Translation catalog (name, language, source, install status) |
| `#__bsms_bible_verses` | Locally stored verse text |
| `#__bsms_scripture_cache` | TTL-based cache for external API responses |

A default catalog of 20 public-domain translations is seeded on install.

## Development

### Dev Environment Setup

The build/release pipeline is provided by [cwm-build-tools](https://github.com/Joomla-Bible-Study/cwm-build-tools), pulled in as a Composer dev dependency. After cloning:

```bash
composer install                # Install PHP deps (incl. cwm-build-tools)
npm ci                          # Install Node deps
cp build.properties.tmpl build.properties   # Local Joomla install paths (gitignored)
composer setup                  # Interactive wizard to populate build.properties
```

`build.properties` declares one or more local Joomla installs (e.g. `j5`, `j6`) with their paths, URLs, and DB/admin credentials. Once configured:

```bash
composer link                   # Symlink src/, media/, language/ into each Joomla install
composer link-check             # Verify symlinks are healthy
composer joomla-install         # Install a fresh Joomla into a configured install dir
composer joomla-latest          # Download the latest Joomla release tarball
composer verify                 # Run all CI checks locally (lint + tests + build)
composer clean                  # Remove built assets and caches
```

### Building Frontend Assets

Source files live in `build/media_source/` (ES6 JS and CSS). Built outputs go to `media/lib_cwmscripture/`.

```bash
npm ci                  # Install dependencies
npm run build           # Build JS (Rollup) and CSS (CSSO)
npm run build:js        # Build JS only
npm run build:css       # Build CSS only
npm run watch           # Watch JS for changes
```

The build produces for each source file:
- Unminified `.js` / `.css` (tracked in git, used by Joomla debug mode)
- Minified `.min.js` + `.min.js.map` + `.min.js.gz` (gitignored, used in production)
- Minified `.min.css` + `.min.css.map` (gitignored)

### Linting

```bash
npm run lint:js                          # ESLint (JS)
composer lint                            # php-cs-fixer dry run (PHP)
composer lint:fix                        # php-cs-fixer auto-fix (PHP)
```

### Building the Installable Package

Creates `build/dist/lib_cwmscripture-{version}.zip` ready for Joomla:

```bash
npm run build                            # Build minified assets first
composer build:package                   # Create ZIP
composer build:package:verbose           # Create ZIP with file listing
```

The resulting ZIP can be:
1. Installed directly via Joomla Extension Manager
2. Included in a `pkg_*.zip` package alongside other extensions (e.g. `pkg_cwmscripture`)
3. Referenced by other build scripts (Proclaim, ScriptureLinks)

### Release Workflow

Releases are driven end-to-end by `cwm-build-tools`. The typical flow:

```bash
composer bump-version -- 1.2.0  # Bump version in manifest, composer.json, package.json
composer changelog -- 1.2.0     # Pull GitHub release notes into build/lib_cwmscripture-changelog.xml
composer release -- 1.2.0       # Full pipeline: bump → build → tag → GitHub release → ARS publish
composer ars-publish            # Publish the built ZIP to Akeeba Release System (standalone)
composer ars-list               # List existing ARS releases
```

> **Note:** Use `composer bump-version`, not `composer bump` — the latter is a built-in Composer 2.4+ command that bumps `composer.json` constraints and will silently override a user-defined script of the same name.

The `<changelogurl>` in `cwmscripture.xml` points to `build/lib_cwmscripture-changelog.xml`, which Joomla reads during update checks.

### Version Checking for Consumers

Other extensions can check the installed library version at install time:

```php
use CWM\Library\Scripture\LibraryVersion;

// Check if library is installed
if (!LibraryVersion::isInstalled()) {
    // Install from embedded ZIP
}

// Check if upgrade is needed
if (LibraryVersion::needsUpgrade('1.2.0')) {
    // Installed version is older than 1.2.0
}

// Check version satisfies minimum
if (LibraryVersion::satisfies('1.0.0')) {
    // Installed version is >= 1.0.0
}

// Get installed version string
$version = LibraryVersion::getInstalledVersion(); // e.g. "1.0.0"
```

## Architecture

### Namespace

All PHP classes live under `CWM\Library\Scripture` (PSR-4 autoloaded from `src/`).

### Bible Provider System

A strategy pattern retrieves scripture passages from multiple sources, resolved automatically by `BibleProviderFactory`:

| Provider | Source | Offline | API Key |
|----------|--------|---------|---------|
| `LocalProvider` | `#__bsms_bible_verses` table | Yes | No |
| `GetBibleProvider` | [GetBible.net v2 API](https://query.getbible.net) | No | No |
| `ApiBibleProvider` | [API.Bible REST API](https://docs.api.bible/) | No | Yes |

**Resolution order:** Local DB &rarr; API.Bible (if enabled + key configured) &rarr; GetBible.net &rarr; fallback. When GDPR mode is enabled, all external providers are disabled and only local translations are served.

The abstract base class (`AbstractBibleProvider`) provides shared utilities:
- Database-backed response caching with configurable TTL
- HTTP requests with retry, exponential backoff, and HTML gatekeeper detection
- Book name/number mappings (standard 1-66 and Proclaim 101-173 schemes)

### Scripture Parsing & Formatting

`ScriptureHelper` parses human-readable references into structured `ScriptureReference` value objects and formats them back:

```
"Luke 7:36-38"  ->  ScriptureReference(booknumber=142, chapterBegin=7, verseBegin=36, ...)
"Genesis 1:1-2:5"  ->  ScriptureReference(booknumber=101, chapterBegin=1, verseBegin=1, chapterEnd=2, verseEnd=5)
```

Supports full book names, common abbreviations (e.g. "Gen", "Matt", "Rev"), and Joomla-translated book names for multilingual sites. Includes 73 books (66 canonical + 7 deuterocanonical).

### Rendering

`ScriptureRenderer` generates HTML output in four display modes:

| Mode | Constant | Behavior |
|------|----------|----------|
| Hidden | `MODE_HIDDEN` | No output |
| Toggle | `MODE_TOGGLE` | Collapsible section with show/hide link |
| Visible | `MODE_VISIBLE` | Always displayed inline |
| Popup | `MODE_POPUP` | Opens in a new browser window |

### Bible Importer

`BibleImporter` downloads entire translations from the GetBible.net v2 API (book-by-book) and batch-inserts verses (500 per batch). Core translations (KJV, WEB) are protected from removal.

### Admin UI

`TranslationsmanagerField` renders a complete translation management interface that can be embedded in any Joomla admin view:
- Provider configuration (GetBible.net, API.Bible) with GDPR mode toggle
- Default version selection and cache TTL settings
- Translation catalog with download, refresh, and removal controls

Use it as a Joomla form field or call `TranslationsmanagerField::renderScriptureTab()` directly from any template.

### Form Fields

| Field | Type | Description |
|-------|------|-------------|
| `BibleTranslationField` | `<optgroup>` select | Language-grouped translation picker with `servable_only` filtering |
| `ApiKeyField` | Masked text | Password input with eye toggle, shows last 4 chars as hint |
| `TranslationsmanagerField` | Custom | Full admin UI for scripture settings and translations |

## Frontend Assets

Web assets are registered via `joomla.asset.json` under the `lib_cwmscripture` namespace:

| Asset | Type | Purpose |
|-------|------|---------|
| `lib_cwmscripture.scripture-text` | CSS | Scripture passage display styling |
| `lib_cwmscripture.scripture-tooltip` | CSS + JS | Hover tooltips for scripture references |
| `lib_cwmscripture.scripture-switcher` | CSS + JS | Bible version switcher UI |
| `lib_cwmscripture.translations-manager` | CSS + JS | Admin translations management |

## Configuration

Settings are stored in the `plg_content_scripturelinks` plugin params and accessed via `ScriptureParamsHelper`:

| Setting | Default | Description |
|---------|---------|-------------|
| `provider_getbible` | `1` | Enable GetBible.net provider |
| `provider_api_bible` | `0` | Enable API.Bible provider |
| `api_bible_api_key` | *(empty)* | API.Bible key ([get one here](https://api.bible/sign-in)) |
| `gdpr_mode` | `0` | Disable all external API calls |
| `default_version` | `kjv` | Default Bible translation |
| `cache_days` | `30` | Cache TTL for external API responses |

## Integration

This library is designed to be consumed by other Joomla extensions:

```php
use CWM\Library\Scripture\Bible\BibleProviderFactory;
use CWM\Library\Scripture\Helper\ScriptureHelper;
use CWM\Library\Scripture\Helper\ScriptureParamsHelper;
use CWM\Library\Scripture\Renderer\ScriptureRenderer;

// Parse a reference
$ref = ScriptureHelper::parseReference('John 3:16-18');

// Get the appropriate provider and fetch the passage
$params   = ScriptureParamsHelper::getParams();
$provider = BibleProviderFactory::getProviderForTranslation('kjv', $params);
$result   = $provider->getPassage('John+3:16-18', 'kjv');

// Render the passage
$renderer = new ScriptureRenderer();
echo $renderer->renderTextPassage($result, ScriptureRenderer::MODE_VISIBLE);
```

### Depending on this library

Joomla has **no dependency tracking for libraries**. Nothing records that your extension needs lib_cwmscripture, and `blockChildUninstall` only protects extensions shipped inside the same package. So the library cannot know you exist unless you tell it.

If you don't, two things go wrong: an administrator can uninstall the library out from under you, and the shared `#__bsms_*` tables get judged orphaned and **dropped** — destroying every locally downloaded translation, yours included.

Registering fixes both. Once you are on the register:

- Uninstalling the library is **refused** while your extension is installed, with a message naming it.
- The shared tables are **never dropped** while you are still there — not by the library, not by `pkg_proclaim`, not by `pkg_cwmscripture`.
- When the last consumer goes, the tables are cleaned up automatically.

#### Complete install script

```php
use CWM\Library\Scripture\Installer\ConsumerRegistry;
use CWM\Library\Scripture\LibraryVersion;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;

return new class () implements InstallerScriptInterface {
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        // Refuse to install against a library that is missing or too old.
        if ($type !== 'uninstall' && !LibraryVersion::satisfies('1.1.6')) {
            Factory::getApplication()->enqueueMessage(
                'This extension requires lib_cwmscripture 1.1.6 or later.',
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // Safe to call on install AND update — re-registering just refreshes the row.
        ConsumerRegistry::register('com_foo', 'component', name: 'Foo');

        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        ConsumerRegistry::unregister('com_foo', 'component');

        return true;
    }

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }
};
```

For a plugin, pass the group as the third argument:

```php
ConsumerRegistry::register('mything', 'plugin', 'content', 'My Thing');
ConsumerRegistry::unregister('mything', 'plugin', 'content');
```

#### Rules that matter

**Register in `postflight`, not `preflight`.** On a fresh install the library's namespace may not be autoloadable until the extension is stored; `postflight` runs after that.

**Register on update too.** `postflight` fires for both, and `register()` is idempotent — it refreshes the existing row rather than duplicating it. Calling it every time is the intended usage.

**Unregistering is good manners, not load-bearing.** The registry cross-checks every entry against `#__extensions` and prunes rows whose extension is gone. A consumer that never unregisters cannot pin the tables forever — but unregister anyway, so the state is correct immediately rather than at the next check.

**Never `DROP` the shared tables yourself.** `#__bsms_bible_translations`, `#__bsms_bible_verses`, `#__bsms_scripture_cache` and `#__bsms_scripture_consumers` belong to the library. It removes them when the last consumer goes.

**Never declare them in `<uninstall><sql>`.** Joomla's `LibraryAdapter` uninstalls the installed extension before writing the new one, so uninstall SQL runs on **every update**, not just on removal. That is exactly how this library once destroyed people's downloaded Bibles. Put any teardown in `script.php::uninstall()` instead, and see the Installer Gotchas section of `CLAUDE.md`.

**Handle a refused uninstall gracefully.** If an admin tries to remove the library while you are registered, Joomla aborts with an error naming your extension. That is the guard working. To remove the library, uninstall the consumers first.

#### What happens on uninstall

| Situation | Result |
|---|---|
| Library upgrade | Always allowed — the guard exempts the installer's internal upgrade cycle |
| Uninstall library, your extension registered | **Refused**, message names your extension |
| Uninstall library, nothing registered or installed | Allowed; shared tables dropped |
| Remove `pkg_proclaim` / `pkg_cwmscripture`, you still registered | Package goes, **tables kept** for you |
| Remove the package, nothing else registered | Package goes, tables dropped |

Proclaim, `plg_content_scripturelinks` and `plg_task_cwmscripture` are recognised without registering.

## Logging

All provider activity is logged to `administrator/logs/cwmscripture.bible.php` using Joomla's logging system under the `cwmscripture.bible` category.

## Accessibility

All CWM-authored code must meet **WCAG 2.2 Level AA**, and this library owns its own conformance.

Proclaim's E2E suite scans every admin and site view with `@axe-core/playwright`. That scan covers this library's rendered output wherever it appears — the scripture fields on the message form, and formatted references in site views. When it flags markup rendered by lib_cwmscripture, **the fix lands in this repository**: Proclaim will not carry workarounds or scan exclusions for CWM-owned code. Its exclusion list is reserved for genuinely third-party widgets (TinyMCE, CodeMirror, Choices.js), each with an upstream report.

This means accessibility regressions in `ScriptureRenderer` output, the form fields in `src/Field/`, and the frontend assets in `media/lib_cwmscripture/` are this repo's responsibility to fix, not Proclaim's to work around.

Whether this repo grows a scan of its own — rather than relying on coverage-through-Proclaim — is this repo's decision to make if and when it has standalone rendering worth gating.

## Contributing

Bug reports and pull requests are welcome on [GitHub Issues](https://github.com/Joomla-Bible-Study/lib_cwmscripture/issues). Before submitting a PR:

1. Run `composer verify` to execute the full CI suite locally (lint + tests + build)
2. Follow Joomla Coding Standards (enforced by `composer lint`)
3. Add PHPDoc `@since` tags to new public/protected methods
4. Keep the `_JEXEC` guard at the top of all PHP files
5. Keep rendered markup WCAG 2.2 AA conformant (see [Accessibility](#accessibility))

## License

GNU General Public License v2.0 or later. See [LICENSE](LICENSE) for details.

Copyright (C) 2026 CWM Team. All rights reserved.
