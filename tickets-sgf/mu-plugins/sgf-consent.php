<?php
/*
 * Plugin Name: SGF Consent
 * Description: Google Consent Mode default pred GTM4WP podľa súhlasu z eventovej stránky (cookie sgf_ev_consent na .sgf.sk).
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * consent.js je kópia z child témy sro.sgf.sk (elementor-widgets/partials/consent.js) — meniť oba naraz.
 * V GTM4WP musí byť Consent mode & consent tools → Google Consent Mode VYPNUTÉ (default nastavuje tento súbor).
 */
add_action('wp_head', function () {
    if (is_admin()) {
        return;
    }
    $file = __DIR__ . '/sgf-consent/consent.js';
    if (!is_readable($file)) {
        return;
    }
    echo '<script data-cfasync="false" data-pagespeed-no-defer>' . file_get_contents($file) . '</script>' . "\n";
}, -100);
