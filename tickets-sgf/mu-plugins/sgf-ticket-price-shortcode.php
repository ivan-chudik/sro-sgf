<?php
/**
 * Plugin Name: SGF ticket price shortcode
 * Description: Prints the currently active pricing-window price of a product.
 *
 * Usage: [sgf_ticket_price id="5957" suffix="/1 deň" tag="none"]
 *
 * Rebuilt 2026-09-24 after the original file was removed: the Elementor
 * landing pages 5969/5970 still render this shortcode through a dynamic tag,
 * so an unregistered tag would leak the literal shortcode text onto a live
 * public page. The price comes from PricingTiers::resolve() - the same active
 * early-bird / normal window the cart uses - and falls back to the product
 * price when the product has no windows.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('sgf_ticket_price', function ($atts) {
    $atts = shortcode_atts(
        ['id' => 0, 'suffix' => '', 'tag' => 'none'],
        $atts,
        'sgf_ticket_price'
    );

    $id = (int) $atts['id'];
    $product = ($id && function_exists('wc_get_product')) ? wc_get_product($id) : null;
    if (!$product) {
        return '';
    }

    $price = null;
    if (class_exists('\\Alttag\\Registrations\\PricingTiers')) {
        $tier = \Alttag\Registrations\PricingTiers::resolve($id);
        if (is_array($tier) && isset($tier['price']) && $tier['price'] !== '') {
            $price = $tier['price'];
        }
    }
    if ($price === null) {
        $price = $product->get_price();
    }

    // wc_price() wraps the amount in markup and glues the currency on with a
    // non-breaking space; the pages expect the bare "6,80 €" string.
    $out = html_entity_decode(wp_strip_all_tags(wc_price($price)), ENT_QUOTES, 'UTF-8');
    $out = str_replace("\xc2\xa0", ' ', $out);
    $out = esc_html($out);

    if ($atts['suffix'] !== '') {
        $out .= '<span class="ticket-price">' . esc_html($atts['suffix']) . '</span>';
    }

    $tag = preg_replace('/[^a-z0-9]/', '', strtolower((string) $atts['tag']));
    if ($tag !== '' && $tag !== 'none') {
        $out = '<' . $tag . '>' . $out . '</' . $tag . '>';
    }

    return $out;
});
