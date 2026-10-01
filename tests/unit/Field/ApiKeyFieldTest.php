<?php

/**
 * ApiKeyField must reuse core's PasswordField, not hand-roll a masked input.
 *
 * getInput() used to hand-build a type="password" input plus a reveal
 * button: a manually concatenated <div class="input-group">, a button with a
 * hardcoded English aria-label, and an inline <script> block duplicated on
 * every field instance toggling input.type between password/text. Core's own
 * PasswordField has provided the same capability since Joomla 1.7.0 --
 * translated, accessible JSHOWPASSWORD/JHIDEPASSWORD labels in a visually-
 * hidden span, and the toggle wired through the asset-managed
 * field.passwordview script instead of inline JS.
 *
 * This submodule's test harness stubs only a few Joomla classes (see
 * tests/bootstrap.php) -- Joomla\CMS\Form\Field\PasswordField is not among
 * them, so live instantiation/reflection on inherited behavior isn't
 * possible here. These assertions read the source directly, the same
 * pattern TranslationsEndpointTest.php already uses in this directory.
 *
 * @package    CWM.Library.Scripture.Tests
 * @copyright  (C) 2026 CWM Team All rights reserved
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace CWM\Library\Scripture\Tests\Field;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ApiKeyFieldTest extends TestCase
{
    /**
     * @return  string  The field's source
     */
    private static function field(): string
    {
        return (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Field/ApiKeyField.php'
        );
    }

    #[TestDox('Extends core PasswordField rather than hand-building a masked input')]
    public function testExtendsPasswordField(): void
    {
        $source = self::field();

        $this->assertStringContainsString('use Joomla\CMS\Form\Field\PasswordField;', $source);
        $this->assertMatchesRegularExpression('/class ApiKeyField extends PasswordField/', $source);
    }

    #[TestDox('No longer hand-rolls the reveal toggle or a <script> block')]
    public function testDropsTheHandRolledToggle(): void
    {
        $source = self::field();

        $this->assertStringNotContainsString('<script>', $source);
        $this->assertStringNotContainsString('aria-label="Toggle visibility"', $source);
        $this->assertStringNotContainsString('icon-eye', $source);
    }

    #[TestDox('Raises the default maxLength so a real Anthropic API key is not truncated')]
    public function testRaisesMaxLengthWhenTheXmlDoesNotSetOne(): void
    {
        $source = self::field();

        // Core's own PasswordField::setup() defaults maxLength to 99 -- fine
        // for a password, but admin.xml's ai_provider list defaults to
        // Claude (Anthropic), whose keys (sk-ant-api03-..., ~108 characters)
        // would be silently truncated by the browser's maxlength enforcement.
        $this->assertStringContainsString("element['maxlength']", $source);
        $this->assertStringContainsString('$this->maxLength = 255;', $source);
    }

    #[TestDox('Sets autocomplete=new-password when the field XML does not specify one')]
    public function testSetsAutocompleteWhenNotSpecified(): void
    {
        $source = self::field();

        $this->assertStringContainsString("element['autocomplete']", $source);
        $this->assertStringContainsString("'new-password'", $source);
    }

    #[TestDox('The deprecated Proclaim-side wrapper has no override to keep in sync')]
    public function testProclaimWrapperIsAZeroOverrideSubclass(): void
    {
        $wrapperPath = \dirname(__DIR__, 5) . '/admin/src/Field/ApiKeyField.php';

        if (!is_file($wrapperPath)) {
            $this->markTestSkipped('Not running inside the Proclaim repo checkout.');
        }

        $wrapper = (string) file_get_contents($wrapperPath);

        $this->assertStringContainsString('extends LibraryApiKeyField', $wrapper);
        $this->assertStringNotContainsString('function getInput', $wrapper);
        $this->assertStringNotContainsString('function setup', $wrapper);
    }
}
