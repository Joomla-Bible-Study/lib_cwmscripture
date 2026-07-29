# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **lib_cwmscripture**, a Joomla 4+ library extension (`type="library"`) that provides Bible scripture retrieval, parsing, rendering, and translation management. It is the shared scripture engine used by the CWM Proclaim component and the `plg_content_scripturelinks` plugin.

- **Namespace**: `CWM\Library\Scripture` (PSR-4 rooted at `src/`)
- **Manifest**: `cwmscripture.xml` (Joomla extension manifest)
- **PHP requirement**: 8.3+ (uses named arguments, `match`, promoted constructor properties, typed `const array`). Matches Proclaim's `"php": "^8.3.0"` requirement.
- **Joomla requirement**: 5.2+ (Joomla 5.2 requires PHP 8.3)
- **License**: GPL-2.0-or-later

## Architecture

### Bible Provider System (`src/Bible/`)

A strategy pattern for retrieving scripture passages from multiple sources:

- **`BibleProviderInterface`** — contract: `getPassage()`, `getAvailableTranslations()`, `returnsText()`, `isOfflineCapable()`
- **`AbstractBibleProvider`** — shared utilities: book name/number mappings, DB-backed cache (`#__bsms_scripture_cache`), HTTP with retry/backoff, HTML gatekeeper detection
- **`BibleProviderFactory`** — static factory with caching. `getProviderForTranslation()` resolves providers in priority order: local DB → API.Bible → GetBible.net → fallback. Respects `gdpr_mode` (disables external providers)
- **Providers**:
  - `LocalProvider` — reads from `#__bsms_bible_verses`, fully offline
  - `GetBibleProvider` — GetBible.net v2 API (`https://query.getbible.net/v2/{translation}/{reference}`)
  - `ApiBibleProvider` — API.Bible REST API with `api-key` header auth, OSIS passage IDs, mandatory FUMS tracking pings

### Book Numbering

Two numbering schemes coexist:
- **Standard (1-66)**: used by `AbstractBibleProvider`, `LocalProvider`, and the `#__bsms_bible_verses` table
- **Proclaim (101-173)**: used by `ScriptureHelper`, `ScriptureReference`, and the Proclaim component (101-166 canonical, 167-173 deuterocanonical)

`AbstractBibleProvider::proclaimToStandard()` / `standardToProclaim()` converts between them.

### Helpers (`src/Helper/`)

- **`ScriptureHelper`** — parses human-readable references (e.g. "Luke 7:36-38") into `ScriptureReference` objects, formats references back to strings, resolves book names/abbreviations via `ABBREVIATIONS` map + Joomla language translations
- **`ScriptureReference`** — value object for a single reference (booknumber, chapter/verse range, version)
- **`ScriptureParamsHelper`** — reads/writes `plg_content_scripturelinks` params from `#__extensions`; syncs `gdpr_mode` to Proclaim's `#__bsms_admin` table

### Renderer (`src/Renderer/`)

`ScriptureRenderer` — generates HTML for scripture display in 4 modes: hidden, toggle (collapsible), visible (always shown), and popup (new window). Loads `lib_cwmscripture.scripture-text` web asset.

### Importer (`src/Importer/`)

`BibleImporter` — downloads entire Bible translations from GetBible.net v2 API book-by-book, batch-inserts into `#__bsms_bible_verses` (batch size 500). Core translations (KJV, WEB) are protected from removal. `seedGetBibleCatalog()` populates the translation catalog.

### Form Fields (`src/Field/`)

- `BibleTranslationField` — grouped select (`<optgroup>` by language) with `servable_only` attribute support
- `ApiKeyField` — masked password input with eye toggle
- `TranslationsmanagerField` — renders the full admin UI for scripture settings and translation management; also callable statically via `renderScriptureTab()`

### Frontend Assets (`media/lib_cwmscripture/`)

Web assets registered via `joomla.asset.json`. CSS for scripture text, tooltips, switcher, translations manager. JS for scripture switcher, tooltip, translations manager, and `cwm-fetch.js` utility.

## Database Tables

All tables use `#__bsms_` prefix (shared with Proclaim):
- `#__bsms_bible_translations` — translation catalog (abbreviation, name, language, source, installed status, verse count)
- `#__bsms_bible_verses` — locally stored verse text (translation, book, chapter, verse, text)
- `#__bsms_scripture_cache` — TTL-based cache for external provider responses

SQL files in `sql/`: `install.mysql.utf8.sql`, `uninstall.mysql.utf8.sql`, `updates/mysql/1.0.0.sql`.

## Key Integration Points

- Settings are stored in `plg_content_scripturelinks` plugin params (accessed via `ScriptureParamsHelper`)
- `TranslationsmanagerField::renderScriptureTab()` can be embedded in any Joomla admin view (used by both the plugin settings page and Proclaim's Admin Center)
- All PHP files are guarded with `\defined('_JEXEC') or die;`
- Logging uses Joomla's `Log` class with category `cwmscripture.bible` → file `cwmscripture.bible.php`

## Provider Gotchas

### GetBible URL encoding

The GetBible v2 endpoint is `https://query.getbible.net/v2/{translation}/{reference}` where `{reference}` looks like `Luke 7:36-38`. The colon between chapter and verse must be left literal — GetBible does **not** accept `%3A`.

```php
// WRONG — urlencode() converts spaces to + and breaks colons differently than rawurlencode
$ref = urlencode($reference);

// WRONG — rawurlencode() correctly encodes spaces as %20 but also encodes the colon to %3A
$ref = rawurlencode($reference);

// CORRECT — rawurlencode for the rest, then put the colon back
$ref = str_replace('%3A', ':', rawurlencode($reference));
```

Use this pattern in `GetBibleProvider` when building request URLs. Other providers (API.Bible) accept either form, so this is GetBible-specific.

## Code Style

- Joomla Coding Standards (PHPCS with PSR1.Files.SideEffects suppressed for `_JEXEC` guard)
- PHPDoc `@since` tags on all public/protected methods
- Named arguments used in constructor calls (e.g. `new BiblePassageResult(text: ..., reference: ...)`)