<?php

/**
 * Plugin Name: Alttag Registrations
 * Description: Alttag Registrations plugin for generating registrations.
 * Version: 1.0
 * Author: Alttag
 * Author URI: https://alttag.digital
 * License: GPL-3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: alttag-registrations
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

use Alttag\Registrations\Core;

// Define plugin constants
define('ALTTAG_REGISTRATIONS_PATH', trailingslashit(__DIR__));
define('ALTTAG_REGISTRATIONS_URL', plugin_dir_url(__FILE__));
define('ALTTAG_REGISTRATIONS_QR_FOLDER', 'qr-pdfs');
define('ALTTAG_REGISTRATIONS_TICKET_DOWNLOAD_FOLDER', 'download-ticket');
define('ALTTAG_REGISTRATIONS_VERIFY_BASE_URL', 'verify');
define('ALTTAG_REGISTRATIONS_QR_CODE_BASE_URL', 'qr-code');

require_once ALTTAG_REGISTRATIONS_PATH . '/vendor/autoload.php';

function alttag_registrations_load_textdomain_for_locale($locale = null)
{
    if ($locale === null) {
        $locale = determine_locale();
    }

    unload_textdomain('alttag-registrations');

    $mofile = WPMU_PLUGIN_DIR . '/alttag-registrations/languages/'
        . 'alttag-registrations-' . $locale . '.mo';

    if (file_exists($mofile)) {
        load_textdomain('alttag-registrations', $mofile);
        return;
    }

    load_muplugin_textdomain('alttag-registrations', 'alttag-registrations/languages');
}

// Get instance function
function alttag_registrations_get_instance()
{
    return Core::getInstance();
}

add_action('plugins_loaded', function () {
    alttag_registrations_load_textdomain_for_locale();
}, 4);

alttag_registrations_get_instance();
