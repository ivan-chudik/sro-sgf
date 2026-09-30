<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Formulár pripomienky → Brevo zoznam kontaktov.
 * Konfigurácia vo wp-config.php (nie v repe):
 *   define( 'XN_BREVO_API_KEY', 'xkeysib-...' );
 *   define( 'XN_BREVO_LIST_SK', 12 );
 *   define( 'XN_BREVO_LIST_EN', 13 );
 * Jazyk sa určuje podľa názvu formulára (Form Name): „Pripomienka SK“ / „Reminder EN“.
 */

function xn_brevo_form_lang( $form_name ) {
    if ( preg_match( '/reminder|\bEN\b/i', $form_name ) ) return 'EN';
    if ( preg_match( '/pripomienka|\bSK\b/i', $form_name ) ) return 'SK';
    return '';
}

add_action( 'elementor_pro/forms/new_record', function( $record, $handler ) {

    if ( ! defined( 'XN_BREVO_API_KEY' ) || ! XN_BREVO_API_KEY ) return;

    $lang = xn_brevo_form_lang( (string) $record->get_form_settings( 'form_name' ) );
    if ( ! $lang ) return;

    $list = $lang === 'EN' && defined( 'XN_BREVO_LIST_EN' ) ? XN_BREVO_LIST_EN : ( defined( 'XN_BREVO_LIST_SK' ) ? XN_BREVO_LIST_SK : 0 );
    if ( ! $list ) return;

    $fields = $record->get( 'fields' );
    $email  = sanitize_email( $fields['email']['value'] ?? '' );
    if ( ! is_email( $email ) ) return;

    $response = wp_remote_post( 'https://api.brevo.com/v3/contacts', [
        'timeout' => 8,
        'headers' => [
            'api-key'      => XN_BREVO_API_KEY,
            'accept'       => 'application/json',
            'content-type' => 'application/json',
        ],
        'body'    => wp_json_encode( [
            'email'         => $email,
            'listIds'       => [ (int) $list ],
            'updateEnabled' => true,
        ] ),
    ] );

    $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        error_log( 'XN Brevo: ' . $email . ' → list ' . $list . ' failed (' . ( is_wp_error( $response ) ? $response->get_error_message() : $code . ' ' . wp_remote_retrieve_body( $response ) ) . ')' );
    }

}, 10, 2 );
