<?php

namespace Alttag\Registrations\Customization;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SK strings the shipped translation packs miss (Elementor Pro has no Slovak
 * pack at all; the WooCommerce one leaves the no-gateway notice English).
 * Keyed on the English source string, so a later pack silently wins.
 * Only applies when current language is Slovak.
 */
add_filter('gettext', function ($translation, $text, $domain) {
    if ($domain !== 'elementor-pro' && $domain !== 'woocommerce') {
        return $translation;
    }

    $replacements = $domain === 'woocommerce' ? [
        'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.'
            => 'Momentálne nie je dostupná žiadna platobná metóda. Ak potrebujete pomoc alebo sa chcete dohodnúť inak, kontaktujte nás.',
    ] : [
        'If you have a coupon code, please apply it below.' => 'Ak máte kupónový kód, zadajte ho nižšie.',
        'Coupon code' => 'Kód kupónu',
        'Apply' => 'Použiť',
    ];

    // Look up the replacement BEFORE resolving the context: this filter
    // fires for EVERY woocommerce/elementor-pro string, including notices
    // raised while the cart is being read. Resolving the language for
    // unmatched strings re-entered ctx() -> cart -> gettext ... (OOM).
    if (!isset($replacements[$text])) {
        return $translation;
    }

    // Only translate to SK when current language is Slovak
    $lang = \Alttag\Registrations\ctx()->currentLanguage();
    if ($lang !== 'sk') {
        return $translation;
    }

    return $replacements[$text];
}, 10, 3);
