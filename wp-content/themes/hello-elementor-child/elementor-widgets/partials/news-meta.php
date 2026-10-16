<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pole „Pôvodný článok“ pri článkoch — CN · Novinky naň presmeruje kartu (napr. článok na sgf.sk).
 * Prázdne = karta vedie na článok na tomto webe.
 */
add_action( 'init', function() {
    register_post_meta( 'post', '_xn_source_url', [
        'type'              => 'string',
        'single'            => true,
        'sanitize_callback' => 'esc_url_raw',
        'auth_callback'     => function() { return current_user_can( 'edit_posts' ); },
    ] );
} );

add_action( 'add_meta_boxes', function() {
    add_meta_box( 'xn_source_url', 'Pôvodný článok (sgf.sk)', function( $post ) {
        wp_nonce_field( 'xn_source_url', 'xn_source_url_nonce' );
        $val = get_post_meta( $post->ID, '_xn_source_url', true );
        echo '<input type="url" name="xn_source_url" value="' . esc_attr( $val ) . '" placeholder="https://www.sgf.sk/sk/article/…" style="width:100%">';
        echo '<p class="description">Karta v sekcii Novinky vedie sem (nové okno). Prázdne = článok na tomto webe.</p>';
    }, 'post', 'side', 'high' );
} );

add_action( 'save_post_post', function( $post_id ) {
    if ( ! isset( $_POST['xn_source_url_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['xn_source_url_nonce'] ) ), 'xn_source_url' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $url = esc_url_raw( trim( wp_unslash( $_POST['xn_source_url'] ?? '' ) ) );
    if ( $url === '' ) delete_post_meta( $post_id, '_xn_source_url' );
    else update_post_meta( $post_id, '_xn_source_url', $url );
} );
