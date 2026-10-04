<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class Assets
{
    private $pluginPath;
    private $pluginUrl;

    public function __construct()
    {
        $this->pluginPath = plugin_dir_path(dirname(__DIR__));
        $this->pluginUrl = plugin_dir_url(dirname(__DIR__));
    }

    public function init()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets()
    {
        $this->enqueueStyles();
        $this->enqueueScripts();
        $this->localizeScripts();
    }

    private function enqueueStyles()
    {
        $cssFile = ALTTAG_REGISTRATIONS_PATH . 'assets/css/styles.css';
        $cssVersion = file_exists($cssFile) ? filemtime($cssFile) : '1.0.1';

        wp_enqueue_style(
            'alttag-registrations-styles',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/styles.css',
            [],
            $cssVersion
        );
    }

    private function enqueueScripts()
    {
        $jsFile = ALTTAG_REGISTRATIONS_PATH . 'assets/js/scripts.js';
        $jsVersion = file_exists($jsFile) ? filemtime($jsFile) : '1.0.1';

        wp_enqueue_script(
            'alttag-registrations-scripts',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/scripts.js',
            ['jquery'],
            $jsVersion,
            true
        );
    }

    private function localizeScripts()
    {
        wp_localize_script(
            'alttag-registrations-scripts',
            'alttagRegistrations',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('alttag-registrations-nonce'),
                'i18n' => [
                    'error' => __('An error occurred', 'alttag-registrations'),
                    'success' => __('Operation successful', 'alttag-registrations')
                ]
            ]
        );
    }

    public function getAssetVersion($file)
    {
        $fullPath = $this->pluginPath . $file;
        return file_exists($fullPath) ? filemtime($fullPath) : '1.0.0';
    }
}
