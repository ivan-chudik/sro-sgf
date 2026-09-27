<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'hello-elementor-child-style',
        get_stylesheet_uri(),
        [],
        wp_get_theme()->get('Version')
    );
}, 20);

require_once get_stylesheet_directory() . '/elementor-widgets/register.php';
