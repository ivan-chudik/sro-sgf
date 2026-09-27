<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/partials/helpers.php';

// ── CSS TOKENY — priority 1 zaručí načítanie pred všetkým ───────────
add_action('wp_head', function() { ?>
<style>
:root {
    --xn-black:   #000000;
    --xn-white:   #ffffff;
    --xn-ink:     #111111;
    --xn-red:     #ff5e5d;
    --xn-pink:    #f65b80;
    --xn-magenta: #df5aa0;
    --xn-orchid:  #ba58ab;
    --xn-purple:  #8e5bae;
    --xn-violet:  #7260b1;
    --xn-blue:    #5762b3;
    --xn-gradient: linear-gradient(90deg, #ff5e5d 0%, #f65b80 22%, #df5aa0 38%, #ba58ab 52%, #8e5bae 66%, #7260b1 82%, #5762b3 100%);
    --xn-font:      "Glacial Indifference", "Jost", "Futura", "Century Gothic", "Helvetica Neue", Arial, sans-serif;
    --xn-font-text: "Jost", "Glacial Indifference", "Futura", "Century Gothic", "Helvetica Neue", Arial, sans-serif;
}
html { scroll-behavior: smooth; }
html body { background: #000; color: #fff; font-family: var(--xn-font-text); -webkit-font-smoothing: antialiased; }

/* ── Visibility utility classes — per-element responsive skrývanie ── */
@media (min-width: 1025px) { .u-hide-desktop { display: none !important; } }
@media (max-width: 1024px) and (min-width: 768px) { .u-hide-tablet { display: none !important; } }
@media (max-width: 767px) { .u-hide-mobile { display: none !important; } }
</style>
<?php }, 1);

$dir = get_stylesheet_directory()     . '/elementor-widgets/';
$uri = get_stylesheet_directory_uri() . '/elementor-widgets/';

$xn_widgets = [
    'header'      => 'Elementor_Widget_Header',
    'hero'        => 'Elementor_Widget_Hero',
    'facts'       => 'Elementor_Widget_Facts',
    'ticker'      => 'Elementor_Widget_Ticker',
    'two-paths'   => 'Elementor_Widget_TwoPaths',
    'reasons'     => 'Elementor_Widget_Reasons',
    'pricing'     => 'Elementor_Widget_Pricing',
    'schedule'    => 'Elementor_Widget_Schedule',
    'competition' => 'Elementor_Widget_Competition',
    'livestream'  => 'Elementor_Widget_Livestream',
    'faq'         => 'Elementor_Widget_Faq',
    'venue'       => 'Elementor_Widget_Venue',
    'reminder'    => 'Elementor_Widget_Reminder',
    'partners'    => 'Elementor_Widget_Partners',
    'footer'      => 'Elementor_Widget_Footer',
    'sticky-cta'  => 'Elementor_Widget_StickyCta',
    'maintenance' => 'Elementor_Widget_Maintenance',
];

// ── ENQUEUE ──────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', function() use ($dir, $uri, $xn_widgets) {

    wp_register_style(
        'xn-base-style',
        $uri . 'partials/base.css',
        [],
        filemtime($dir . 'partials/base.css')
    );

    foreach ( $xn_widgets as $name => $class ) {
        wp_register_script(
            $name . '-script',
            $uri . $name . '/script.js',
            ['jquery'],
            filemtime($dir . $name . '/script.js'),
            true
        );
        wp_register_style(
            $name . '-style',
            $uri . $name . '/style.css',
            ['xn-base-style'],
            filemtime($dir . $name . '/style.css')
        );
    }

});

// ── KATEGÓRIA ────────────────────────────────────────────────────────
add_action('elementor/elements/categories_registered', function($elements_manager) {
    $elements_manager->add_category('custom-widgets', [
        'title' => 'Christmas Nitra',
        'icon'  => 'eicon-star',
    ]);
});

// ── REGISTER WIDGETS ─────────────────────────────────────────────────
add_action('elementor/widgets/register', function($widgets_manager) use ($dir, $xn_widgets) {

    require_once $dir . 'partials/controls.php';

    foreach ( $xn_widgets as $name => $class ) {
        require_once $dir . $name . '/class.php';
        $widgets_manager->register( new $class() );
    }

});
