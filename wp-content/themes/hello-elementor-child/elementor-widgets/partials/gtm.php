<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Google Tag Manager — spoločný kontajner SGF eventov a tickets.sgf.sk.
 * consent.js beží pred kontajnerom: default z cookies Complianzu / sgf_ev_consent, update po kliknutí v lište.
 * Rovnaký skript je na tickets.sgf.sk v mu-plugins/sgf-consent/ — meniť oba naraz.
 */

const XN_GTM_ID = 'GTM-KKRD2SZH';

function xn_gtm_enabled() {
    return ! is_admin() && ! isset( $_GET['elementor-preview'] );
}

add_action( 'wp_head', function() {
    if ( ! xn_gtm_enabled() ) return;
    ?>
<script><?php echo file_get_contents( __DIR__ . '/consent.js' ); ?></script>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo esc_js( XN_GTM_ID ); ?>');</script>
    <?php
}, 0 );

add_action( 'wp_body_open', function() {
    if ( ! xn_gtm_enabled() ) return;
    ?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( XN_GTM_ID ); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <?php
}, 0 );
