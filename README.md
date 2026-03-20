# lib_cwmscripture

A Joomla 4+ library extension that provides Bible scripture retrieval, parsing, rendering, and translation management. It serves as the shared scripture engine for the [CWM Proclaim](https://www.christianwebministries.org) component and the `plg_content_scripturelinks` plugin.

## Requirements

- Joomla 5.2+
- PHP 8.3+
- MySQL 5.7+ / MariaDB 10.2+ (InnoDB, utf8mb4)

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

## Logging

All provider activity is logged to `administrator/logs/cwmscripture.bible.php` using Joomla's logging system under the `cwmscripture.bible` category.

## License

GNU General Public License v2.0 or later. See [LICENSE](LICENSE) for details.

Copyright (C) 2026 CWM Team. All rights reserved.
