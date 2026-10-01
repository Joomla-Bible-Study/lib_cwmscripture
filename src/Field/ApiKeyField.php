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

use Joomla\CMS\Form\Field\PasswordField;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * API Key Field - masked text input with reveal toggle
 *
 * Extends core's PasswordField rather than hand-building a masked input: the
 * same visible result (dots + an eye-toggle button), but with an accessible,
 * translated label (JSHOWPASSWORD/JHIDEPASSWORD in a visually-hidden span,
 * not a hardcoded English aria-label) and the toggle wired through core's
 * own asset-managed `field.passwordview` script instead of an inline script
 * tag duplicated on every field instance.
 *
 * @since  1.1.0
 */
class ApiKeyField extends PasswordField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.1.0
     */
    protected $type = 'ApiKey';

    /**
     * Method to attach a Form object to the field.
     *
     * @param   \SimpleXMLElement  $element  The SimpleXMLElement object representing the `<field>` tag.
     * @param   mixed              $value    The form field value to validate.
     * @param   string             $group    The field name group control value.
     *
     * @return  bool  True on success.
     *
     * @since   __DEPLOY_VERSION__
     */
    #[\Override]
    public function setup(\SimpleXMLElement $element, $value, $group = null)
    {
        $return = parent::setup($element, $value, $group);

        if ($return) {
            // PasswordField::setup() defaults maxLength to 99, tuned for a
            // user password. This field stores third-party API credentials
            // of many shapes, not a password -- a real Anthropic key
            // (sk-ant-api03-..., ~108 characters, the configured default
            // provider in admin.xml's ai_provider list) would be silently
            // truncated by the browser's own maxlength enforcement. Only
            // applies when the field's own XML doesn't set maxlength itself.
            if (!$this->element['maxlength']) {
                $this->maxLength = 255;
            }

            // FormField defaults autocomplete to 'on' when the XML doesn't
            // set it; an API key is exactly the kind of one-off credential
            // a browser should never offer to save or autofill.
            if ((string) $this->element['autocomplete'] === '') {
                $this->autocomplete = 'new-password';
            }
        }

        return $return;
    }
}
