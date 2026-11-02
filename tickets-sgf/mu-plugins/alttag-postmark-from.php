<?php
/**
 * Plugin Name: Alttag Postmark From Override
 * Description: Forces the envelope/header From address of every wp_mail()
 *              call to the only sender domain verified in Postmark
 *              (riverstream.sk). Without this, easy-wp-smtp's own
 *              `wp_mail_from` filter injects an address on an unverified
 *              domain, which Postmark rejects with 422.
 * Version:     1.0.0
 * Author:      alttag.digital
 *
 * easy-wp-smtp / wp-mail-smtp register their From filters at PHP_INT_MAX.
 * mu-plugins load before regular plugins, so registering here at load time
 * would place us *before* them in the same-priority queue and they would
 * win. Registering on `init` puts us after them in the callback list at the
 * same priority, so we run last.
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Environment guard: this file pairs with alttag-postmark.php, which carries
 * a live Postmark server token, so it must stay inert anywhere that is not
 * production. Dev checkouts share the same code but resolve to a
 * *.dev.simoncihra.sk host.
 */
if (parse_url(site_url(), PHP_URL_HOST) !== 'tickets.sgf.sk') {
    return;
}

if (!defined('ALTTAG_POSTMARK_FROM')) {
    define('ALTTAG_POSTMARK_FROM', 'no-reply@riverstream.sk');
}

add_action('init', function () {
    add_filter('wp_mail_from', 'alttag_postmark_force_from', PHP_INT_MAX);
    add_filter('wp_mail_from_name', 'alttag_postmark_force_from_name', PHP_INT_MAX);
}, PHP_INT_MAX);

function alttag_postmark_force_from($from_email)
{
    return ALTTAG_POSTMARK_FROM;
}

function alttag_postmark_force_from_name($from_name)
{
    // Site title, not the core default "WordPress".
    $name = trim(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
    return $name !== '' ? $name : $from_name;
}
