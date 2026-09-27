<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── CSS TOKENY — priority 1 zaručí načítanie pred všetkým ───────────
add_action('wp_head', function() { ?>
<style>
:root {
    --color-primary:   #e63946;
    --color-secondary: #457b9d;
    --color-bg:        #1a1a2e;
    --color-text:      #f1faee;
    --font-heading:    'Montserrat', sans-serif;
    --font-body:       'Inter', sans-serif;
}

/* ── Visibility utility classes — per-element responsive skrývanie ── */
@media (min-width: 1025px) { .u-hide-desktop { display: none !important; } }
@media (max-width: 1024px) and (min-width: 768px) { .u-hide-tablet { display: none !important; } }
@media (max-width: 767px) { .u-hide-mobile { display: none !important; } }
</style>
<?php }, 1);

$dir = get_stylesheet_directory()     . '/elementor-widgets/';
$uri = get_stylesheet_directory_uri() . '/elementor-widgets/';

// ── ENQUEUE ──────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', function() use ($dir, $uri) {

    // príklad — skopíruj a premenuj pri každom novom widgete:
    // wp_register_script(
    //     'hero-script',
    //     $uri . 'hero/script.js',
    //     ['jquery'],
    //     filemtime($dir . 'hero/script.js'),
    //     true
    // );
    // wp_register_style(
    //     'hero-style',
    //     $uri . 'hero/style.css',
    //     [],
    //     filemtime($dir . 'hero/style.css')
    // );

});

// ── REGISTER WIDGETS ─────────────────────────────────────────────────
add_action('elementor/widgets/register', function($widgets_manager) use ($dir) {

    // príklad — skopíruj a premenuj pri každom novom widgete:
    // require_once $dir . 'hero/class.php';
    // $widgets_manager->register( new Elementor_Widget_Hero() );

});
