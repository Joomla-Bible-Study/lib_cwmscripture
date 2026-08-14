<?php

/**
 * The translations panel and its script must agree on where the endpoint is.
 *
 * A Joomla library cannot own a `com_ajax` endpoint — only a component or a
 * plugin can — so the URL names whichever extension is serving it. That made the
 * hardcoded literal in the script an undeclared dependency on
 * plg_content_scripturelinks being installed *and enabled*: `com_ajax` dispatches
 * only to enabled plugins, so disabling it left Proclaim's scripture tab issuing
 * requests that resolved to nothing, with no error to explain it.
 *
 * The field now passes the endpoint as data, the way the passage renderer already
 * does. These assertions are the coupling: one side emits the attribute, the
 * other reads it, and neither is any use alone.
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Field;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class TranslationsEndpointTest extends TestCase
{
    /**
     * @return  string  The field's source
     *
     * @since   1.1.18
     */
    private static function field(): string
    {
        return (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Field/TranslationsmanagerField.php'
        );
    }

    /**
     * @return  string  The script's source
     *
     * @since   1.1.18
     */
    private static function script(): string
    {
        return (string) file_get_contents(
            \dirname(__DIR__, 3) . '/build/media_source/js/bible-translations.es6.js'
        );
    }

    /**
     * @return  void
     *
     * @since   1.1.18
     */
    #[TestDox('the field passes the endpoint to the script as data')]
    public function testTheFieldEmitsTheEndpoint(): void
    {
        self::assertStringContainsString(
            'data-ajax-url="',
            self::field(),
            'The config div no longer carries data-ajax-url, so the script falls back to a hardcoded URL and the '
            . 'endpoint stops being the field\'s decision.'
        );

        self::assertStringContainsString(
            'private static function ajaxUrl()',
            self::field(),
            'ajaxUrl() is where the serving extension is chosen. A consumer that wants to serve these actions '
            . 'itself changes that one method.'
        );
    }

    /**
     * @return  void
     *
     * @since   1.1.18
     */
    #[TestDox('the script takes the endpoint from the field rather than assuming one')]
    public function testTheScriptReadsTheEndpoint(): void
    {
        self::assertStringContainsString(
            'config.dataset.ajaxUrl',
            self::script(),
            'The script has stopped reading data-ajax-url, so it is back to assuming which extension answers.'
        );
    }

    /**
     * ⚠️ The reported defect: `com_ajax` dispatches only to enabled plugins, so
     * a disabled content plugin left this panel spinning with nothing to say why.
     *
     * @return  void
     *
     * @since   1.1.18
     */
    #[TestDox('the panel says so when nothing is serving its endpoint')]
    public function testADisabledHostIsReported(): void
    {
        $field = self::field();

        self::assertStringContainsString(
            'private static function endpointIsServed()',
            $field,
            'Without this check the panel renders normally and every action fails silently.'
        );

        self::assertStringContainsString(
            'PluginHelper::isEnabled(',
            $field,
            'endpointIsServed() must actually ask whether the plugin is enabled — installed is not sufficient, '
            . 'because com_ajax dispatches only to enabled plugins.'
        );

        self::assertStringContainsString(
            'LIB_CWMSCRIPTURE_TRANSLATIONS_ENDPOINT_UNAVAILABLE',
            $field,
            'The check exists but nothing tells the administrator, which was the reported defect.'
        );
    }

    /**
     * @return  void
     *
     * @since   1.1.18
     */
    #[TestDox('the message the warning uses is translated')]
    public function testTheWarningStringExists(): void
    {
        $ini = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/language/en-GB/en-GB.lib_cwmscripture.ini'
        );

        self::assertStringContainsString(
            'LIB_CWMSCRIPTURE_TRANSLATIONS_ENDPOINT_UNAVAILABLE=',
            $ini,
            'The warning would render as a raw language key.'
        );
    }
}
