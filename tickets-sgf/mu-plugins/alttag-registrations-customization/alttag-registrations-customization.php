<?php

/*
 * Plugin Name: Alttag Registrations Customization
 * Description: Customizes the Alttag Registrations functionality.
 * Version: 1.0
 * Author: AltTag
 * Author URI: https://alttag.digital
 * Text Domain: alttag-registrations-customization
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALTTAG_REGISTRATIONS_CUSTOMIZATION_PATH', __DIR__);
define('ALTTAG_REGISTRATIONS_CUSTOMIZATION_URL', plugin_dir_url(__FILE__));

// Load includes after WordPress is ready
add_action('plugins_loaded', function () {
    $files = glob(ALTTAG_REGISTRATIONS_CUSTOMIZATION_PATH . '/includes/*.php');
    foreach ($files as $file) {
        require_once $file;
    }
}, 5);

// Load customization textdomains when main plugin loads them
add_action('alttag_registrations_load_textdomains', function ($locale) {
    $mofile = WPMU_PLUGIN_DIR . '/alttag-registrations-customization/languages/'
        . 'alttag-registrations-customization-' . $locale . '.mo';
    if (file_exists($mofile)) {
        load_textdomain('alttag-registrations-customization', $mofile);
    }
});


