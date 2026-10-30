<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function() {
    register_setting( 'general', 'xn_eb_end', [
        'type'              => 'string',
        'sanitize_callback' => function( $value ) {
            $value = trim( (string) $value );
            return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
        },
    ] );

    add_settings_field( 'xn_eb_end', 'Christmas Nitra — koniec Early Bird', function() {
        $value = (string) get_option( 'xn_eb_end', '' );
        echo '<input type="datetime-local" name="xn_eb_end" id="xn_eb_end" value="' . esc_attr( $value ) . '">';
        echo '<p class="description">Od tohto času sa na webe skryjú Early Bird texty a karty ukážu plné ceny. '
            . 'Prázdne = 1. 11. 2026 00:00. Teraz nastavené: <b>' . esc_html( xn_eb_end_label() ) . '</b>. '
            . 'Náhľad stavu po skončení: <code>?xn_eb=over</code> za adresou stránky.</p>';
    }, 'general', 'default', [ 'label_for' => 'xn_eb_end' ] );
} );
