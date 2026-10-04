<?php

namespace Alttag\Registrations\Customization;

use function Alttag\Registrations\ctx;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Accreditation info notice — shown above whichever selection renders
 * first (day or participant-type). Both hooks are registered so the
 * notice appears once whether the product uses days, types, or both,
 * plus a checkout-level fallback for products that render no selection
 * UI at all. In-person only.
 */
function render_accreditation_notice(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }

    $cart = ctx()->cart();
    if (!$cart->hasInperson) {
        return;
    }

    // xnt carts render the accreditation note (.xnt-acc) inside the
    // configurator block itself - skip the standalone notice there.
    if (function_exists(__NAMESPACE__ . '\\xnt_checkout_recap_applies') && xnt_checkout_recap_applies()) {
        return;
    }

    $rendered = true;

    $lang = ctx()->currentLanguage();

    $text = $lang === 'en'
        ? 'Competitors, coaches, and members of the team officially registered and accredited'
            . ' through the Slovak Gymnastics Federation have their entry included'
            . ' in their accreditation, so they do not need to purchase it.'
        : 'Súťažiaci, tréneri a členovia realizačného tímu, ktorí sú oficiálne registrovaní'
            . ' a akreditovaní cez Slovenskú gymnastickú federáciu, majú vstup zahrnutý'
            . ' v akreditácii, takže si ho nemusia kupovať.';

    echo '<div class="day-selection-info">' . esc_html($text) . '</div>';
}

add_action('alttag_registrations_before_day_selection', __NAMESPACE__ . '\\render_accreditation_notice');
add_action('alttag_registrations_before_participant_type_selection', __NAMESPACE__ . '\\render_accreditation_notice');

// Fallback: a product with no _event_available_dates / _participant_types
// (or with _alttag_skip_session_selection) renders no selection UI, so
// neither hook above ever fires. SelectionManager::renderCheckoutUI is on
// woocommerce_before_checkout_billing_form at the default priority 10 —
// run after it so the static guard makes this a no-op whenever a selection
// already carried the notice, and it lands above the billing form otherwise.
add_action('woocommerce_before_checkout_billing_form', __NAMESPACE__ . '\\render_accreditation_notice', 20);

/**
 * Restore the Country/Region label on the Slovak checkout.
 *
 * Position and label of the native address block are the core plugin's job now:
 * CheckoutManager::addCheckoutFields copies the Field Builder `country` entry's
 * label and priority onto billing_country, so the server already renders
 * "Krajina" - a woocommerce_billing_fields filter here would only duplicate it.
 *
 * What overwrote it was WooCommerce's own address-i18n.js: on every
 * country_to_state_changed it rewrites each address field's label from
 * wc_address_i18n_params.locale, whose `default` entry is
 * WC_Countries::get_default_address_fields(). That array is untouched by the
 * checkout-field filters and carries the woocommerce-domain "Country / Region",
 * which this site has no Slovak translation for - so the Slovak checkout ended
 * up in English a moment after load. Filtering the default address fields fixes
 * the server render and the JS payload in one place.
 *
 * Only the label: WC_Countries::get_country_locale() force-resets the country
 * entry's `hidden` and `required` after this filter runs.
 */
add_filter('woocommerce_default_address_fields', function ($fields) {
    if (!isset($fields['country']) || !class_exists('\Alttag\Registrations\FieldBuilder')) {
        return $fields;
    }

    $definition = \Alttag\Registrations\FieldBuilder::getField('country');
    if ($definition) {
        $label = \Alttag\Registrations\FieldBuilder::getFieldLabel($definition);
        if ($label !== '') {
            $fields['country']['label'] = $label;
        }
    }

    return $fields;
});

/**
 * Enqueue project-specific styles
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_checkout()) {
        return;
    }

    wp_enqueue_style(
        'alttag-registrations-customization-styles',
        ALTTAG_REGISTRATIONS_CUSTOMIZATION_URL . 'assets/styles/styles.css',
        [],
        filemtime(ALTTAG_REGISTRATIONS_CUSTOMIZATION_PATH . '/assets/styles/styles.css')
    );
});

/**
 * Slovak copy for the fixed day-count picker (1-day / 2-day tickets).
 *
 * The core plugin ships the English wording through _n(); its Slovak .po is
 * regenerated upstream, so until then the Slovak text lives here, in the same
 * ctx()->currentLanguage() style as the accreditation notice above.
 */
function required_days_text(int $count, bool $is_error): string
{
    if (ctx()->currentLanguage() !== 'sk') {
        return '';
    }

    // Slovak plurals: 1 deň, 2-4 dni, 5+ dní.
    $day = $count === 1 ? 'deň' : ($count < 5 ? 'dni' : 'dní');
    $sentence = sprintf('Vyberte si presne %d %s.', $count, $day);

    return $is_error ? 'Prosím, ' . lcfirst($sentence) : $sentence;
}

add_filter('alttag_registrations_selection_required_hint', function ($text, $count) {
    return required_days_text((int) $count, false) ?: $text;
}, 10, 2);

add_filter('alttag_registrations_selection_required_error', function ($text, $count) {
    return required_days_text((int) $count, true) ?: $text;
}, 10, 2);
