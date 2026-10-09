<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Google Tag Manager — spoločný kontajner SGF eventov a tickets.sgf.sk.
 * Consent default (denied) beží pred kontajnerom, update posiela GTM tag „Consent - Default + Complianz Bridge“.
 * Iné ID kontajnera: define( 'XN_GTM_ID', 'GTM-XXXXXXX' ); vo wp-config.php.
 */

function xn_gtm_id() {
    return defined( 'XN_GTM_ID' ) ? (string) XN_GTM_ID : 'GTM-KKRD2SZH';
}

function xn_gtm_enabled() {
    return ! is_admin() && ! isset( $_GET['elementor-preview'] ) && xn_gtm_id() !== '';
}

add_action( 'wp_head', function() {
    if ( ! xn_gtm_enabled() ) return;
    $id = xn_gtm_id();
    ?>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){ dataLayer.push(arguments); }
gtag('consent', 'default', {
    analytics_storage: 'denied',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    functionality_storage: 'granted',
    security_storage: 'granted',
    wait_for_update: 500
});
</script>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo esc_js( $id ); ?>');</script>
    <?php
}, 0 );

add_action( 'wp_body_open', function() {
    if ( ! xn_gtm_enabled() ) return;
    ?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( xn_gtm_id() ); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <?php
}, 0 );
