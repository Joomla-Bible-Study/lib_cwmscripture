<?php

/**
 * Part of CWM Scripture Library
 *
 * @package    CWM.Library.Scripture
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://www.christianwebministries.org
 */

namespace CWM\Library\Scripture\Field;

use CWM\Library\Scripture\Helper\ScriptureParamsHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Renders the complete Scripture management UI.
 *
 * Used as a Joomla form field on the plugin settings page, and also
 * callable via the static `renderScriptureTab()` method from any
 * template (e.g. Proclaim's Admin Center).
 *
 * @since  1.0.0
 */
class TranslationsmanagerField extends FormField
{
    /**
     * @var  string
     * @since  1.0.0
     */
    protected $type = 'Translationsmanager';

    /**
     * @inheritDoc
     */
    protected function getInput(): string
    {
        // Load library language file
        Factory::getLanguage()->load('lib_cwmscripture', JPATH_ADMINISTRATOR);
        Factory::getLanguage()->load('lib_cwmscripture', JPATH_LIBRARIES . '/cwmscripture');

        $prefix = $this->formControl
            ? $this->formControl . '[' . $this->group . ']'
            : 'jform[params]';

        $mediaBase = Uri::root(true) . '/media/lib_cwmscripture';

        // Register library assets via WebAssetManager
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseScript('lib_cwmscripture.cwm-fetch', 'media/lib_cwmscripture/js/cwm-fetch.js');
        $wa->registerAndUseScript('lib_cwmscripture.bible-translations', 'media/lib_cwmscripture/js/bible-translations.js', [], [], ['lib_cwmscripture.cwm-fetch']);
        $wa->registerAndUseStyle('lib_cwmscripture.translations-manager', 'media/lib_cwmscripture/css/translations-manager.css');

        $switcherCss = Uri::root(true) . '/media/system/css/fields/switcher.css';
        $html        = '<link rel="stylesheet" href="' . self::esc($switcherCss) . '" />';

        $html .= self::renderScriptureTab($prefix);

        return $html;
    }

    /**
     * @inheritDoc
     */
    protected function getLabel(): string
    {
        return '';
    }

    /**
     * Render the complete Scripture tab UI.
     *
     * Can be called from any template. Outputs the same HTML for both
     * the plugin settings page and Proclaim's Admin Center.
     *
     * @param   string  $fieldPrefix  Form field name prefix (e.g. "jform[params]")
     *
     * @return  string  HTML output
     *
     * @since  1.1.0
     */
    public static function renderScriptureTab(string $fieldPrefix = 'jform[params]'): string
    {
        // Load library language file
        Factory::getLanguage()->load('lib_cwmscripture', JPATH_ADMINISTRATOR);
        Factory::getLanguage()->load('lib_cwmscripture', JPATH_LIBRARIES . '/cwmscripture');

        $params             = ScriptureParamsHelper::getParams();
        $token              = Session::getFormToken();
        $providerGetbible   = (int) $params->get('provider_getbible', 1);
        $providerApiBible   = (int) $params->get('provider_api_bible', 0);
        $apiBibleKey        = (string) $params->get('api_bible_api_key', '');
        $providerBibleBrain = (int) $params->get('provider_biblebrain', 0);
        $bibleBrainKey      = (string) $params->get('biblebrain_api_key', '');
        $gdprMode           = (int) $params->get('gdpr_mode', 0);
        $defaultVersion     = (string) $params->get('default_version', 'kjv');
        $cacheDays          = (int) $params->get('cache_days', 30);
        $adminLang          = 'en-GB';

        try {
            $adminLang = Factory::getApplication()->getLanguage()->getTag();
        } catch (\Throwable) {
        }

        $html = '';

        // ── Two-column panels: Providers (left) + Settings (right) ──
        $html .= '<div class="row" id="scripture-settings">';

        // Left column: Providers
        $html .= '<div class="col-12 col-lg-6">';
        $html .= '<div class="cwmadmin-panel mb-4">';
        $html .= '<h3 class="tab-description">' . Text::_('LIB_CWMSCRIPTURE_PROVIDERS_TITLE') . '</h3>';
        $html .= '<p class="text-muted">' . Text::_('LIB_CWMSCRIPTURE_PROVIDERS_DESC') . '</p>';

        $html .= self::renderSwitcher(
            $fieldPrefix . '[provider_getbible]',
            'jform_params_provider_getbible',
            Text::_('LIB_CWMSCRIPTURE_GETBIBLE_LABEL'),
            Text::_('LIB_CWMSCRIPTURE_GETBIBLE_DESC'),
            $providerGetbible
        );

        $html .= self::renderSwitcher(
            $fieldPrefix . '[provider_api_bible]',
            'jform_params_provider_api_bible',
            Text::_('LIB_CWMSCRIPTURE_APIBIBLE_LABEL'),
            Text::_('LIB_CWMSCRIPTURE_APIBIBLE_DESC'),
            $providerApiBible
        );

        // API Key
        $maskedKey = $apiBibleKey !== ''
            ? str_repeat("\u{2022}", 20) . substr($apiBibleKey, -4)
            : '';

        $apiKeyHidden = $providerApiBible ? '' : ' style="display:none;"';
        $html .= '<div class="control-group" id="api-bible-key-group"' . $apiKeyHidden . '>';
        $html .= '<div class="control-label"><label for="jform_params_api_bible_api_key">'
            . Text::_('LIB_CWMSCRIPTURE_APIKEY_LABEL') . '</label></div>';
        $html .= '<div class="controls"><div class="input-group">';
        $html .= '<input type="password" name="' . self::esc($fieldPrefix . '[api_bible_api_key]') . '" '
            . 'id="jform_params_api_bible_api_key" '
            . 'value="' . self::esc($apiBibleKey) . '" '
            . 'placeholder="' . self::esc($maskedKey) . '" '
            . 'class="form-control" />';
        $html .= '<button type="button" class="btn btn-secondary" id="jform_params_api_key_toggle" '
            . 'aria-label="Toggle visibility">'
            . '<span class="icon-eye" aria-hidden="true"></span></button>';
        $html .= '</div>';
        $html .= '<div class="form-text">' . Text::_('LIB_CWMSCRIPTURE_APIKEY_DESC') . '</div>';
        $html .= '</div></div>';

        // Get API Key + Sync buttons
        $html .= '<div id="api-bible-key-row" class="mb-3" style="display:none;">';
        $html .= '<a href="https://api.bible/sign-in" target="_blank" rel="noopener noreferrer" '
            . 'class="btn btn-sm btn-outline-secondary">'
            . '<i class="icon-key" aria-hidden="true"></i> '
            . Text::_('LIB_CWMSCRIPTURE_GET_API_KEY') . '</a>';
        $html .= '</div>';

        $html .= '<div id="api-bible-sync-row" class="mb-3" style="display:none;">';
        $html .= '<button type="button" class="btn btn-sm btn-primary" id="btn-sync-api-bible">'
            . '<i class="icon-refresh" aria-hidden="true"></i> '
            . Text::_('LIB_CWMSCRIPTURE_SYNC_TRANSLATIONS') . '</button>';
        $html .= '<span id="api-bible-sync-status" class="ms-2 small"></span>';
        $html .= '</div>';

        // ── Bible Brain (Faith Comes By Hearing) ──
        $html .= '<hr class="my-3">';

        $html .= self::renderSwitcher(
            $fieldPrefix . '[provider_biblebrain]',
            'jform_params_provider_biblebrain',
            Text::_('LIB_CWMSCRIPTURE_BIBLEBRAIN_LABEL'),
            Text::_('LIB_CWMSCRIPTURE_BIBLEBRAIN_DESC'),
            $providerBibleBrain
        );

        // Bible Brain API Key
        $maskedBbKey = $bibleBrainKey !== ''
            ? str_repeat("\u{2022}", 20) . substr($bibleBrainKey, -4)
            : '';

        $bbKeyHidden = $providerBibleBrain ? '' : ' style="display:none;"';
        $html .= '<div class="control-group" id="biblebrain-key-group"' . $bbKeyHidden . '>';
        $html .= '<div class="control-label"><label for="jform_params_biblebrain_api_key">'
            . Text::_('LIB_CWMSCRIPTURE_BIBLEBRAIN_APIKEY_LABEL') . '</label></div>';
        $html .= '<div class="controls"><div class="input-group">';
        $html .= '<input type="password" name="' . self::esc($fieldPrefix . '[biblebrain_api_key]') . '" '
            . 'id="jform_params_biblebrain_api_key" '
            . 'value="' . self::esc($bibleBrainKey) . '" '
            . 'placeholder="' . self::esc($maskedBbKey) . '" '
            . 'class="form-control" />';
        $html .= '<button type="button" class="btn btn-secondary" id="jform_params_biblebrain_key_toggle" '
            . 'aria-label="Toggle visibility">'
            . '<span class="icon-eye" aria-hidden="true"></span></button>';
        $html .= '</div>';
        $html .= '<div class="form-text">' . Text::_('LIB_CWMSCRIPTURE_BIBLEBRAIN_APIKEY_DESC') . '</div>';
        $html .= '</div></div>';

        // Get Bible Brain API Key button
        $html .= '<div id="biblebrain-key-row" class="mb-3" style="display:none;">';
        $html .= '<a href="https://4.dbt.io/api/sign-up" target="_blank" rel="noopener noreferrer" '
            . 'class="btn btn-sm btn-outline-secondary">'
            . '<i class="icon-key" aria-hidden="true"></i> '
            . Text::_('LIB_CWMSCRIPTURE_BIBLEBRAIN_GET_KEY') . '</a>';
        $html .= '</div>';

        $html .= '</div></div>'; // end panel, end left column

        // Right column: Settings
        $html .= '<div class="col-12 col-lg-6">';
        $html .= '<div class="cwmadmin-panel mb-4">';
        $html .= '<h3 class="tab-description">' . Text::_('LIB_CWMSCRIPTURE_SETTINGS_TITLE') . '</h3>';

        // Default version
        $html .= '<div class="control-group">';
        $html .= '<div class="control-label"><label for="jform_params_default_version">'
            . Text::_('LIB_CWMSCRIPTURE_VERSION_LABEL') . '</label></div>';
        $html .= '<div class="controls">';
        $html .= self::renderVersionSelect(
            $fieldPrefix . '[default_version]',
            'jform_params_default_version',
            $defaultVersion
        );
        $html .= '<div class="form-text">' . Text::_('LIB_CWMSCRIPTURE_VERSION_DESC') . '</div>';
        $html .= '</div></div>';

        // Cache days
        $html .= '<div class="control-group">';
        $html .= '<div class="control-label"><label for="jform_params_cache_days">'
            . Text::_('LIB_CWMSCRIPTURE_CACHE_LABEL') . '</label></div>';
        $html .= '<div class="controls">';
        $html .= '<input type="number" name="' . self::esc($fieldPrefix . '[cache_days]') . '" '
            . 'id="jform_params_cache_days" value="' . $cacheDays . '" '
            . 'min="1" max="365" class="form-control" />';
        $html .= '<div class="form-text">' . Text::_('LIB_CWMSCRIPTURE_CACHE_DESC') . '</div>';
        $html .= '</div></div>';

        $html .= '</div></div>'; // end panel, end right column
        $html .= '</div>'; // end row

        // ── Local Translations panel ──
        $html .= '<div class="row"><div class="col-12"><div class="cwmadmin-panel mb-4">';
        $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
        $html .= '<h3 class="tab-description mb-0" id="translations-card-header">'
            . Text::_('LIB_CWMSCRIPTURE_LOCAL_TRANSLATIONS') . '</h3>';
        $html .= '<div class="btn-group btn-group-sm">';
        $html .= '<button type="button" class="btn btn-primary d-none" id="btn-update-all-translations">'
            . '<i class="icon-download" aria-hidden="true"></i> '
            . Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_UPDATE_ALL') . '</button>';
        $html .= '<button type="button" class="btn btn-danger d-none" id="btn-remove-all-translations">'
            . '<i class="icon-trash" aria-hidden="true"></i> '
            . Text::_('LIB_CWMSCRIPTURE_STR_REMOVE_ALL') . '</button>';
        $html .= '<button type="button" class="btn btn-outline-secondary" id="btn-refresh-translations">'
            . '<i class="icon-refresh" aria-hidden="true"></i></button>';
        $html .= '</div></div>';
        $html .= '<p class="text-muted">' . Text::_('LIB_CWMSCRIPTURE_LOCAL_TRANSLATIONS_DESC') . '</p>';

        $html .= '<div id="translations-list">';
        $html .= '<div class="text-center py-3">'
            . '<span class="spinner-border spinner-border-sm" role="status"></span> '
            . Text::_('LIB_CWMSCRIPTURE_STR_LOADING') . '</div>';
        $html .= '</div>';
        $html .= '</div></div></div>';

        // ── Config div for bible-translations.js ──
        $html .= '<div id="bible-translations-config" class="d-none"'
            . ' data-gdpr-mode="' . $gdprMode . '"'
            . ' data-token="' . $token . '"'
            . ' data-str-loading="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_LOADING')) . '"'
            . ' data-str-no-translations="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_NO_TRANSLATIONS')) . '"'
            . ' data-str-load-error="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_LOAD_ERROR')) . '"'
            . ' data-str-title="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_TITLE')) . '"'
            . ' data-str-abbreviation="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_ABBREVIATION')) . '"'
            . ' data-str-source="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SOURCE')) . '"'
            . ' data-str-status="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_STATUS')) . '"'
            . ' data-str-verses="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_VERSES')) . '"'
            . ' data-str-installed="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_INSTALLED')) . '"'
            . ' data-str-not-installed="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_NOT_INSTALLED')) . '"'
            . ' data-str-download="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_DOWNLOAD')) . '"'
            . ' data-str-downloading="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_DOWNLOADING')) . '"'
            . ' data-str-remove="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_REMOVE')) . '"'
            . ' data-str-download-failed="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_DOWNLOAD_FAILED')) . '"'
            . ' data-str-confirm-remove="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_CONFIRM_REMOVE')) . '"'
            . ' data-str-bundled-done="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BUNDLED_DONE')) . '"'
            . ' data-str-status-ready="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_STATUS_READY')) . '"'
            . ' data-str-status-installed="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_STATUS_INSTALLED')) . '"'
            . ' data-str-status-none="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_STATUS_NONE')) . '"'
            . ' data-str-status-unknown="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_STATUS_UNKNOWN')) . '"'
            . ' data-str-remove-all="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_REMOVE_ALL')) . '"'
            . ' data-str-confirm-remove-all="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_CONFIRM_REMOVE_ALL')) . '"'
            . ' data-str-size="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SIZE')) . '"'
            . ' data-str-total-size="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_TOTAL_SIZE')) . '"'
            . ' data-str-syncing="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SYNCING')) . '"'
            . ' data-str-sync-complete="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SYNC_COMPLETE')) . '"'
            . ' data-str-sync-failed="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SYNC_FAILED')) . '"'
            . ' data-str-gdpr-disabled="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_GDPR_DISABLED')) . '"'
            . ' data-str-online="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_ONLINE')) . '"'
            . ' data-str-language="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_LANGUAGE')) . '"'
            . ' data-str-all-languages="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_ALL_LANGUAGES')) . '"'
            . ' data-str-filter-all="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_FILTER_ALL')) . '"'
            . ' data-str-filter-installed="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_FILTER_INSTALLED')) . '"'
            . ' data-str-filter-not-installed="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_FILTER_NOT_INSTALLED')) . '"'
            . ' data-str-filter-in-use="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_FILTER_IN_USE')) . '"'
            . ' data-str-search-placeholder="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SEARCH_PLACEHOLDER')) . '"'
            . ' data-str-usage-count="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_USAGE_COUNT')) . '"'
            . ' data-str-usage-badge="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_USAGE_BADGE')) . '"'
            . ' data-str-suggested="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SUGGESTED')) . '"'
            . ' data-str-showing-count="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SHOWING_COUNT')) . '"'
            . ' data-admin-language="' . self::esc($adminLang) . '"'
            . ' data-str-core-translation="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_CORE_TRANSLATION')) . '"'
            . ' data-str-core-cannot-remove="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_CORE_CANNOT_REMOVE')) . '"'
            . ' data-str-suggested-desc="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_SUGGESTED_DESC')) . '"'
            . ' data-str-online-only="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_ONLINE_ONLY')) . '"'
            . ' data-str-online-only-desc="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_ONLINE_ONLY_DESC')) . '"'
            . ' data-str-provider-disable-confirm="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_PROVIDER_DISABLE_CONFIRM')) . '"'
            . ' data-str-provider-cleanup-done="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_PROVIDER_CLEANUP_DONE')) . '"'
            . ' data-str-bible-refresh="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_REFRESH')) . '"'
            . ' data-str-bible-refreshing="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_REFRESHING')) . '"'
            . ' data-str-bible-update-all="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_UPDATE_ALL')) . '"'
            . ' data-str-bible-update-all-desc="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_UPDATE_ALL_DESC')) . '"'
            . ' data-str-bible-updating-all="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_UPDATING_ALL')) . '"'
            . ' data-str-bible-update-all-complete="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_UPDATE_ALL_COMPLETE')) . '"'
            . ' data-str-bible-downloaded-at="' . self::esc(Text::_('LIB_CWMSCRIPTURE_STR_BIBLE_DOWNLOADED_AT')) . '"'
            . '></div>';

        // Inline JS for eye toggles + provider show/hide
        $html .= '<script>'
            . '(function(){'
            // API.Bible eye toggle
            . 'var t=document.getElementById("jform_params_api_key_toggle");'
            . 'if(t){t.addEventListener("click",function(){'
            . 'var i=document.getElementById("jform_params_api_bible_api_key");'
            . 'var s=this.querySelector("span");'
            . 'if(i.type==="password"){i.type="text";s.className="icon-eye-close";}'
            . 'else{i.type="password";s.className="icon-eye";}});}'
            // Bible Brain eye toggle
            . 'var t2=document.getElementById("jform_params_biblebrain_key_toggle");'
            . 'if(t2){t2.addEventListener("click",function(){'
            . 'var i=document.getElementById("jform_params_biblebrain_api_key");'
            . 'var s=this.querySelector("span");'
            . 'if(i.type==="password"){i.type="text";s.className="icon-eye-close";}'
            . 'else{i.type="password";s.className="icon-eye";}});}'
            // API.Bible provider toggle — show/hide key group + button rows
            . 'var radios=document.querySelectorAll("input[name=\\"jform[params][provider_api_bible]\\"]");'
            . 'var keyGrp=document.getElementById("api-bible-key-group");'
            . 'var keyRow=document.getElementById("api-bible-key-row");'
            . 'var syncRow=document.getElementById("api-bible-sync-row");'
            . 'function toggleApi(){'
            . 'var c=document.querySelector("input[name=\\"jform[params][provider_api_bible]\\"]:checked");'
            . 'var on=c&&c.value==="1";'
            . 'if(keyGrp)keyGrp.style.display=on?"":"none";'
            . 'if(keyRow)keyRow.style.display=on?"":"none";'
            . 'if(syncRow)syncRow.style.display=on?"":"none";'
            . '}'
            . 'radios.forEach(function(r){r.addEventListener("change",toggleApi);});'
            . 'toggleApi();'
            // Bible Brain provider toggle — show/hide key group + button row
            . 'var bbRadios=document.querySelectorAll("input[name=\\"jform[params][provider_biblebrain]\\"]");'
            . 'var bbKeyGrp=document.getElementById("biblebrain-key-group");'
            . 'var bbKeyRow=document.getElementById("biblebrain-key-row");'
            . 'function toggleBb(){'
            . 'var c=document.querySelector("input[name=\\"jform[params][provider_biblebrain]\\"]:checked");'
            . 'var on=c&&c.value==="1";'
            . 'if(bbKeyGrp)bbKeyGrp.style.display=on?"":"none";'
            . 'if(bbKeyRow)bbKeyRow.style.display=on?"":"none";'
            . '}'
            . 'bbRadios.forEach(function(r){r.addEventListener("change",toggleBb);});'
            . 'toggleBb();'
            . '})();'
            . '</script>';

        return $html;
    }

    /**
     * Render a Joomla-style radio switcher.
     *
     * @param   string  $name   Form input name
     * @param   string  $id     Element ID
     * @param   string  $label  Label text
     * @param   string  $desc   Description text
     * @param   int     $value  Current value (0 or 1)
     *
     * @return  string
     *
     * @since  1.1.0
     */
    private static function renderSwitcher(string $name, string $id, string $label, string $desc, int $value): string
    {
        $noAttr  = !$value ? ' checked class="active"' : '';
        $yesAttr = $value ? ' checked class="active"' : '';

        $html = '<div class="control-group">';
        $html .= '<div class="control-label"><label>' . self::esc($label) . '</label></div>';
        $html .= '<div class="controls">';
        $html .= '<fieldset id="' . $id . '">';
        $html .= '<legend class="visually-hidden">' . self::esc($label) . '</legend>';
        $html .= '<div class="switcher">';
        $html .= '<input type="radio" id="' . $id . '0" name="' . self::esc($name) . '" value="0"' . $noAttr . '>';
        $html .= '<label for="' . $id . '0">' . Text::_('JNO') . '</label>';
        $html .= '<input type="radio" id="' . $id . '1" name="' . self::esc($name) . '" value="1"' . $yesAttr . '>';
        $html .= '<label for="' . $id . '1">' . Text::_('JYES') . '</label>';
        $html .= '<span class="toggle-outside"><span class="toggle-inside"></span></span>';
        $html .= '</div>';
        $html .= '</fieldset>';

        if ($desc) {
            $html .= '<div class="form-text">' . self::esc($desc) . '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Render the default version select dropdown.
     *
     * @param   string  $name     Form input name
     * @param   string  $id       Element ID
     * @param   string  $current  Currently selected abbreviation
     *
     * @return  string
     *
     * @since  1.1.0
     */
    private static function renderVersionSelect(string $name, string $id, string $current): string
    {
        $options = [];

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName(['abbreviation', 'name', 'language', 'installed']))
                ->from($db->quoteName('#__bsms_bible_translations'))
                ->order($db->quoteName('name') . ' ASC');
            $db->setQuery($query);

            foreach ($db->loadObjectList() as $row) {
                $label = $row->name . ' (' . strtoupper($row->abbreviation) . ')';

                if ((int) $row->installed === 1) {
                    $label .= ' ✓';
                }

                $options[] = (object) ['value' => $row->abbreviation, 'text' => $label];
            }
        } catch (\Throwable) {
        }

        if (empty($options)) {
            $options = [
                (object) ['value' => 'kjv', 'text' => 'King James Version (KJV)'],
                (object) ['value' => 'web', 'text' => 'World English Bible (WEB)'],
            ];
        }

        $html = '<select name="' . self::esc($name) . '" id="' . $id . '" class="form-select">';

        foreach ($options as $opt) {
            $sel = $opt->value === $current ? ' selected' : '';
            $html .= '<option value="' . self::esc($opt->value) . '"' . $sel . '>'
                . self::esc($opt->text) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    /**
     * Escape a string for safe HTML attribute/content output.
     *
     * @param   string  $s  String to escape
     *
     * @return  string
     *
     * @since  1.1.0
     */
    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_COMPAT, 'UTF-8');
    }
}
