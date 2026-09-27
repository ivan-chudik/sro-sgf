<?php
if ( ! defined( 'ABSPATH' ) ) exit;

const XN_TICKETS_SK = 'https://tickets.sgf.sk/sk/christmas-nitra-vstupenky/';
const XN_TICKETS_EN = 'https://tickets.sgf.sk/christmas-nitra-tickets/';
const XN_HOME_SK    = 'https://sro.sgf.sk/';
const XN_HOME_EN    = 'https://sro.sgf.sk/en/';

function xn_asset( $path ) {
    return get_stylesheet_directory_uri() . '/elementor-widgets/assets/' . ltrim( $path, '/' );
}

/**
 * Jazyk aktuálnej stránky: 'en' ak URL stránky začína /en, alebo je EN locale; inak 'sk'.
 * V editore sa číta permalink editovaného dokumentu (render ide cez admin-ajax).
 */
function xn_lang() {
    $path = '';
    if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
        $doc = \Elementor\Plugin::$instance->documents->get_current();
        if ( $doc ) {
            $path = (string) wp_parse_url( get_permalink( $doc->get_main_id() ), PHP_URL_PATH );
        }
    }
    if ( $path === '' && function_exists( 'get_queried_object_id' ) && get_queried_object_id() ) {
        $path = (string) wp_parse_url( get_permalink( get_queried_object_id() ), PHP_URL_PATH );
    }
    if ( $path === '' ) {
        $path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    }
    if ( preg_match( '#^/en(/|$)#', $path ) ) return 'en';
    return strpos( determine_locale(), 'en' ) === 0 ? 'en' : 'sk';
}

function xn_tickets_url() {
    return xn_lang() === 'en' ? XN_TICKETS_EN : XN_TICKETS_SK;
}

function xn_is_external( $url ) {
    if ( ! preg_match( '#^(https?:)?//#i', $url ) ) return false;
    $host = wp_parse_url( $url, PHP_URL_HOST );
    $home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    return $host && strcasecmp( $host, (string) $home ) !== 0;
}

/**
 * href + target/rel z Elementor URL controlu. Prázdna URL → $fallback.
 * Externé linky (iná doména) sa otvárajú vždy v novom okne.
 */
function xn_link_attrs( $setting, $fallback = '', $force_blank = false ) {
    $url      = is_array( $setting ) ? ( $setting['url'] ?? '' ) : (string) $setting;
    $external = is_array( $setting ) && ! empty( $setting['is_external'] );
    if ( trim( $url ) === '' ) $url = $fallback;
    if ( $url === '' ) return '';

    $attrs = ' href="' . esc_url( $url ) . '"';
    if ( $force_blank || $external || xn_is_external( $url ) ) {
        $attrs .= ' target="_blank" rel="noopener"';
    }
    return $attrs;
}

function xn_force_blank( $html ) {
    return preg_replace_callback( '#<a\s[^>]*>#i', function( $m ) {
        $tag = $m[0];
        if ( stripos( $tag, 'target=' ) !== false ) return $tag;
        if ( ! preg_match( '#href=("|\')([^"\']*)\1#i', $tag, $href ) || ! xn_is_external( html_entity_decode( $href[2] ) ) ) return $tag;
        return preg_replace( '#>$#', ' target="_blank" rel="noopener">', $tag );
    }, $html );
}

/** WYSIWYG — bezpečný pattern: bez prázdnych <p>, kses, externé linky v novom okne. */
function xn_wysiwyg( $raw ) {
    $clean = preg_replace( '/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', (string) $raw );
    $clean = trim( $clean );
    return $clean === '' ? '' : xn_force_blank( wp_kses_post( $clean ) );
}

/** Krátky text s inline značkami (<b>, <em>, <br>, <a>). */
function xn_inline( $raw ) {
    return xn_force_blank( wp_kses( (string) $raw, [
        'b'      => [],
        'strong' => [],
        'em'     => [],
        'br'     => [],
        'a'      => [ 'href' => [], 'target' => [], 'rel' => [] ],
    ] ) );
}

/** TEXTAREA → pole neprázdnych riadkov. */
function xn_lines( $raw ) {
    return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $raw ) ), 'strlen' ) );
}

/** Riadok "a | b | c" → ['a','b','c'] */
function xn_cols( $line, $count ) {
    return array_pad( array_map( 'trim', explode( '|', $line, $count ) ), $count, '' );
}

function xn_filled( $settings, $key ) {
    return isset( $settings[ $key ] ) && trim( (string) $settings[ $key ] ) !== '';
}

function xn_on( $settings, $key ) {
    return ( $settings[ $key ] ?? '' ) === 'yes';
}

/** Štítok (WG / Open / MSR…) → modifikátor podľa zoznamov v controls. */
function xn_tag_variant( $label, $grad_list, $orchid_list ) {
    $norm = function( $list ) {
        return array_map( 'strtolower', array_filter( array_map( 'trim', explode( ',', (string) $list ) ) ) );
    };
    $l = strtolower( trim( $label ) );
    if ( in_array( $l, $norm( $grad_list ), true ) )   return 'grad';
    if ( in_array( $l, $norm( $orchid_list ), true ) ) return 'orchid';
    return 'dark';
}

/** <img> z MEDIA controlu; pri obrázku z knižnice aj srcset. */
function xn_img( $media, $class = '', $alt = '', $extra = [] ) {
    $id  = (int) ( $media['id'] ?? 0 );
    $url = $media['url'] ?? '';
    if ( ! $id && ! $url ) return '';

    $attrs = array_merge( [ 'class' => $class, 'alt' => $alt ], $extra );
    if ( $id && function_exists( 'wp_get_attachment_image' ) ) {
        $img = wp_get_attachment_image( $id, 'full', false, $attrs );
        if ( $img ) return $img;
    }
    $html = '<img src="' . esc_url( $url ) . '"';
    foreach ( $attrs as $k => $v ) {
        $html .= ' ' . $k . '="' . esc_attr( $v ) . '"';
    }
    return $html . ' />';
}

/** Zoznam Elementor šablón pre SELECT control. */
function xn_elementor_templates() {
    $options   = [ '' => '— vyber šablónu —' ];
    $templates = get_posts( [
        'post_type'      => 'elementor_library',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    foreach ( $templates as $t ) {
        $options[ $t->ID ] = $t->post_title;
    }
    return $options;
}

function xn_render_template( $template_id ) {
    $template_id = (int) $template_id;
    if ( ! $template_id || ! class_exists( '\Elementor\Plugin' ) ) return '';
    if ( $template_id === (int) get_the_ID() ) return '';
    return \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id );
}

/** Tlačidlo cez partial. */
function xn_button( $args ) {
    $btn = $args;
    include __DIR__ . '/button.php';
}
