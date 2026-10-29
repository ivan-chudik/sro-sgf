<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MuPluginsLoader
 * Handles loading of MU plugins and their translations
 */
class MuPluginsLoader {
    /**
     * Textdomain notices to suppress (plugins that load translations too early)
     * @var array
     */
    private $suppress_textdomain_notices = [
        'woocommerce-superfaktura',
        'woocommerce',
    ];

    /**
     * List of MU plugins to load
     * @var array
     */
    private $plugins = [
        'alttag-registrations/alttag-registrations.php',
        'alttag-registrations-customization/alttag-registrations-customization.php',
    ];

    /**
     * Translation domains and their paths
     * @var array
     */
    private $translation_domains = [
        'alttag-registrations-customization' => 'alttag-registrations-customization/languages',
        'alttag-registrations' => 'alttag-registrations/languages',
    ];

    /**
     * Initialize the loader
     */
    public function init() {
        add_action('muplugins_loaded', [$this, 'loadTranslations'], -1);
        add_filter('doing_it_wrong_trigger_error', [$this, 'suppressTextdomainNotices'], 10, 3);
        $this->loadPlugins();
        add_filter('loco_plugins_data', [$this, 'registerWithLocoTranslate'], 10, 1);
        add_filter('loco_debug', '__return_false');
    }

    /**
     * Suppress _load_textdomain_just_in_time notices for specific domains
     *
     * @param bool   $trigger       Whether to trigger the error
     * @param string $function_name The function that was called incorrectly
     * @param string $message       The error message
     * @return bool
     */
    public function suppressTextdomainNotices(
        $trigger,
        $function_name,
        $message
    ) {
        if ($function_name === '_load_textdomain_just_in_time') {
            foreach ($this->suppress_textdomain_notices as $domain) {
                if (strpos($message, $domain) !== false) {
                    return false;
                }
            }
        }
        return $trigger;
    }

    /**
     * Load translations for all MU plugins
     */
    public function loadTranslations() {
        $locale = determine_locale();

        foreach ($this->translation_domains as $domain => $path) {
            // Try WordPress default location first
            $wp_mofile = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';
            
            if (file_exists($wp_mofile)) {
                load_textdomain($domain, $wp_mofile);
                continue;
            }

            // Then try MU plugin specific location
            $plugin_mofile = WPMU_PLUGIN_DIR . '/' . $path . '/' . $domain . '-' . $locale . '.mo';
            if (file_exists($plugin_mofile)) {
                load_textdomain($domain, $plugin_mofile);
            }
        }
    }

    /**
     * Load all MU plugins
     */
    private function loadPlugins() {
        foreach ($this->plugins as $plugin) {
            require_once WPMU_PLUGIN_DIR . '/' . $plugin;
        }
    }

    /**
     * Register MU plugins with Loco Translate
     * 
     * @param array $plugins Existing plugins array
     * @return array Modified plugins array
     */
    public function registerWithLocoTranslate($plugins) {
        foreach ($this->plugins as $plugin) {
            $data = get_plugin_data(trailingslashit(WPMU_PLUGIN_DIR) . $plugin);
            $data['basedir'] = WPMU_PLUGIN_DIR;
            $plugins[$plugin] = $data;
        }
        return $plugins;
    }
}

// Initialize the loader
$loader = new MuPluginsLoader();
$loader->init();
