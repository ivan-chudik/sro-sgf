<?php
/**
 * Plugin Name: Alttag Postmark Bridge
 * Description: Drop-in relay of every wp_mail() call through Postmark's
 *              HTTP API — no SMTP, no per-site setup, no admin UI.
 *              Copy the file into wp-content/mu-plugins/ on any WordPress
 *              site and mail delivery + tracking is instant. Every event
 *              is visible in the Postmark dashboard.
 * Version:     1.0.0
 * Author:      alttag.digital
 *
 * ─────────────────────────────────────────────────────────────────────
 * Config
 * ─────────────────────────────────────────────────────────────────────
 * Only the Postmark Server Token has to be set. Everything else — sender
 * verification, message stream, dashboard — lives in Postmark's UI.
 *
 * Rotate the token in Postmark → Servers → API Tokens after this file
 * ships to git; the constant is defined-if-undefined so wp-config.php
 * can override it without touching this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Environment guard: this file carries a live Postmark server token, so it
 * must stay inert anywhere that is not production. Dev checkouts share the
 * same code but resolve to a *.dev.simoncihra.sk host.
 */
if (parse_url(site_url(), PHP_URL_HOST) !== 'tickets.sgf.sk') {
    return;
}

if (!defined('ALTTAG_POSTMARK_TOKEN')) {
    define('ALTTAG_POSTMARK_TOKEN', '0f1c3906-0aa8-4292-b332-ab162bdc8704');
}
if (!defined('ALTTAG_POSTMARK_STREAM')) {
    define('ALTTAG_POSTMARK_STREAM', 'outbound');
}

// ─────────────────────────────────────────────────────────────────────
// Short-circuit wp_mail via `pre_wp_mail` (WP 5.7+). Any callback that
// returns non-null replaces WordPress's built-in PHPMailer send; the
// value we return becomes wp_mail()'s return value (true/false).
// This means easy-wp-smtp / any other SMTP layer becomes a no-op:
// their phpmailer_init hook never fires because wp_mail returns first.
// ─────────────────────────────────────────────────────────────────────

add_filter('pre_wp_mail', 'alttag_postmark_send', 10, 2);

function alttag_postmark_send($null, $atts)
{
    $to          = $atts['to']          ?? '';
    $subject     = $atts['subject']     ?? '';
    $message     = $atts['message']     ?? '';
    $headers     = $atts['headers']     ?? [];
    $attachments = $atts['attachments'] ?? [];

    if (empty($to) || empty($subject)) {
        return null; // let core handle degenerate cases (it will fail there)
    }

    $parsed = alttag_postmark_parse_headers($headers);

    // From — honour explicit header, then wp_mail_from filters, then
    // fall back to wordpress@<site-host>. This matches core semantics
    // so plugins overriding sender behave identically.
    $from_email = '';
    $from_name  = '';
    if (!empty($parsed['from'])) {
        [$from_name, $from_email] = alttag_postmark_split_addr($parsed['from']);
        unset($parsed['from']);
    }
    if (!$from_email) {
        $from_email = apply_filters('wp_mail_from', 'wordpress@' . wp_parse_url(home_url(), PHP_URL_HOST));
    }
    if (!$from_name) {
        $from_name = apply_filters('wp_mail_from_name', 'WordPress');
    }

    // HTML vs text — same source of truth as core wp_mail: explicit
    // Content-Type header, then `wp_mail_content_type` filter default.
    $is_html = false;
    if (!empty($parsed['content-type'])) {
        $is_html = (stripos($parsed['content-type'], 'text/html') !== false);
        unset($parsed['content-type']);
    } else {
        $is_html = (stripos((string) apply_filters('wp_mail_content_type', 'text/plain'), 'text/html') !== false);
    }

    $reply_to = $parsed['reply-to'] ?? '';
    $cc       = $parsed['cc']       ?? '';
    $bcc      = $parsed['bcc']      ?? '';
    unset($parsed['reply-to'], $parsed['cc'], $parsed['bcc']);

    // Base payload. Message stream is a Postmark concept for isolating
    // transactional vs marketing sends; we hardcode 'outbound' which is
    // Postmark's default transactional stream on every new Server.
    $body = [
        'From'          => $from_name ? "\"{$from_name}\" <{$from_email}>" : $from_email,
        'To'            => is_array($to) ? implode(', ', $to) : $to,
        'Subject'       => $subject,
        'MessageStream' => ALTTAG_POSTMARK_STREAM,
        'TrackOpens'    => true,
        'TrackLinks'    => 'None',
    ];
    if ($is_html) {
        $body['HtmlBody'] = $message;
        // ALWAYS send a plain-text alternative alongside HTML. Mail
        // providers (especially Microsoft 365 EOP) penalise HTML-only
        // mail as spam-adjacent — legitimate senders ship both parts of
        // multipart/alternative. Auto-derive text from the HTML: strip
        // tags, decode entities, collapse repeated whitespace.
        $text = wp_strip_all_tags($message, true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $body['TextBody'] = trim((string) $text);
    } else {
        $body['TextBody'] = $message;
    }
    if ($reply_to) {
        $body['ReplyTo'] = $reply_to;
    }
    if ($cc) {
        $body['Cc'] = $cc;
    }
    if ($bcc) {
        $body['Bcc'] = $bcc;
    }

    // Multi-dimensional filtering in the Postmark dashboard:
    //   - `Tag` = pure site slug ("nobullshit-camp"). Postmark's Tag column
    //     shows this in the activity list at a glance and one-click filters
    //     to every message from that site — regardless of message type.
    //   - `Metadata.type` = message category ("receipt", "ticket",
    //     "invoice-paid", "invoice-unpaid", "participant", "admin", "generic").
    //     Filter via `Filters → Metadata → type=...` in Postmark UI, or the
    //     search bar (`metadata.type:receipt`) to slice across all sites.
    //   - `Metadata.site` = full site host for reporting / programmatic use.
    //   - `Metadata.participant_id` / `.order_id` when resolvable.
    //
    // Type resolution precedence (first match wins):
    //   1. `X-Alttag-Postmark-Type` header — explicit override by the caller.
    //      Set this in callers that know the semantic (e.g. Stripe receipt
    //      sender). Value is used verbatim after sanitisation.
    //   2. Subject-line heuristics for the common cases we know about
    //      (receipt / invoice / ticket) — works without touching the caller.
    //   3. Recipient == admin_email → 'admin'.
    //   4. Recipient matches a participant post → 'participant'.
    //   5. Otherwise → 'generic'.
    $first_recipient = alttag_postmark_first_recipient($to);
    $site_host       = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $site_slug       = alttag_postmark_site_slug($site_host);
    $meta            = ['site' => $site_host];

    $participant_id = alttag_postmark_resolve_participant($first_recipient);
    if ($participant_id) {
        $meta['participant_id'] = (string) $participant_id;
        $order_id = (int) get_post_meta($participant_id, 'order_id', true);
        if ($order_id) {
            $meta['order_id'] = (string) $order_id;
        }
    }

    $type_override = isset($parsed['x-alttag-postmark-type'])
        ? sanitize_key((string) $parsed['x-alttag-postmark-type'])
        : '';
    unset($parsed['x-alttag-postmark-type']);

    $meta['type']    = alttag_postmark_resolve_type(
        $type_override,
        (string) $subject,
        $first_recipient,
        (bool) $participant_id
    );
    $body['Tag']      = $site_slug;
    $body['Metadata'] = $meta;

    // Forward any remaining custom headers verbatim.
    if (!empty($parsed)) {
        $body['Headers'] = [];
        foreach ($parsed as $name => $value) {
            $body['Headers'][] = ['Name' => $name, 'Value' => (string) $value];
        }
    }

    // Attachments — Postmark expects base64-encoded content inline. Ticket
    // PDF + invoice PDF are the common cases; images / arbitrary MIME are
    // fine too since we sniff extension → type.
    if (!empty($attachments)) {
        $body['Attachments'] = [];
        foreach ((array) $attachments as $path) {
            // is_readable() is also true for directories, and a 0-byte file
            // passes every path check: Postmark rejects the whole message with
            // "Zero-sized attachments not allowed", so drop those here.
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                error_log('[alttag-postmark] empty/unreadable attachment skipped: ' . $path);
                continue;
            }
            $body['Attachments'][] = [
                'Name'        => basename($path),
                'Content'     => base64_encode($content),
                'ContentType' => alttag_postmark_mime_of($path),
            ];
        }
    }

    $response = wp_remote_post('https://api.postmarkapp.com/email', [
        'timeout' => 15,
        'headers' => [
            'Accept'                  => 'application/json',
            'Content-Type'            => 'application/json',
            'X-Postmark-Server-Token' => ALTTAG_POSTMARK_TOKEN,
        ],
        'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        error_log('[alttag-postmark] transport error: ' . $response->get_error_message());
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $response->get_error_message(), $atts));
        return false;
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    if ($status_code < 200 || $status_code >= 300) {
        $api_body = json_decode((string) wp_remote_retrieve_body($response), true);
        $api_msg  = $api_body['Message'] ?? 'Unknown Postmark error';
        error_log("[alttag-postmark] API error {$status_code}: {$api_msg}");
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $api_msg, $atts));
        return false;
    }

    return true;
}

/**
 * Resolve the semantic message type (used as `Metadata.type` in Postmark).
 *
 * Precedence (first match wins):
 *   1. Explicit override header from the caller (X-Alttag-Postmark-Type).
 *   2. Subject-line heuristics — matches the subject patterns emitted by
 *      alttag-registrations' WooCommerceManager::getEmailSubject() and
 *      StripeManager receipt flow. Covers English + Slovak wording so
 *      multi-language sites work out of the box.
 *   3. Recipient == wp_options.admin_email → 'admin'.
 *   4. Recipient resolved to a participant post → 'participant'.
 *   5. Fallback → 'generic'.
 */
function alttag_postmark_resolve_type($override, $subject, $recipient, $is_participant)
{
    if ($override !== '') {
        return $override;
    }

    $lower = function_exists('mb_strtolower')
        ? mb_strtolower((string) $subject, 'UTF-8')
        : strtolower((string) $subject);

    $contains = static function ($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    };

    // Receipt / Účtenka — Stripe or similar payment provider receipts.
    // Deliberately matched before invoice patterns because a subject can
    // contain both words and the receipt semantic is more specific.
    if ($contains($lower, 'receipt') || $contains($lower, 'účtenka')) {
        return 'receipt';
    }

    // Invoice status. Combined ticket-and-invoice subjects fall into the
    // invoice bucket because the invoice is the payment-relevant artefact
    // (mail delivery issues cluster around bogus PDFs in these).
    if ($contains($lower, 'unpaid invoice') || $contains($lower, 'nezaplatená faktúra')) {
        return 'invoice-unpaid';
    }
    if ($contains($lower, 'paid invoice') || $contains($lower, 'zaplatená faktúra')) {
        return 'invoice-paid';
    }

    // Ticket-only (no invoice in subject).
    if ($contains($lower, 'ticket') || $contains($lower, 'vstupenka')) {
        return 'ticket';
    }

    $admin_email = strtolower((string) get_option('admin_email'));
    if ($admin_email !== '' && $recipient === $admin_email) {
        return 'admin';
    }

    if ($is_participant) {
        return 'participant';
    }

    return 'generic';
}

/**
 * Turn a site host into a Postmark-friendly Tag slug. Postmark Tag column
 * is short and lower-case-y — we want it human-readable at a glance so
 * anyone scanning the activity feed can tell which site emitted a given
 * mail. Strip a leading `www.`, lowercase everything, replace dots with
 * hyphens: `registration.nobullshit.camp` → `registration-nobullshit-camp`.
 */
function alttag_postmark_site_slug($host)
{
    $host = strtolower((string) $host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return str_replace('.', '-', $host);
}

/**
 * Split "Name <addr@example.com>" into [name, email].
 */
function alttag_postmark_split_addr($raw)
{
    $raw = trim((string) $raw);
    if (preg_match('/^(.+?)\s*<(.+?)>$/', $raw, $m)) {
        return [trim($m[1], " \"\'"), trim($m[2])];
    }
    return ['', $raw];
}

/**
 * Normalise wp_mail headers to a lowercase name → value map. Accepts
 * both array-of-strings ("Name: value") and CRLF-joined string forms
 * because wp_mail's caller contract allows either.
 */
function alttag_postmark_parse_headers($headers)
{
    $lines = [];
    if (is_array($headers)) {
        foreach ($headers as $line) {
            if (is_string($line)) {
                $lines[] = $line;
            }
        }
    } elseif (is_string($headers) && $headers !== '') {
        $lines = preg_split('/\r?\n/', $headers);
    }

    $out = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $out[strtolower(trim($name))] = trim($value);
    }
    return $out;
}

/**
 * Return the first recipient from whatever shape wp_mail was called with.
 * Handles: "a@b.com", "a@b.com, c@d.com", "Name <a@b.com>", ["a@b.com", ...].
 */
function alttag_postmark_first_recipient($to)
{
    if (is_array($to)) {
        $to = reset($to);
    }
    $to = (string) $to;
    if (strpos($to, ',') !== false) {
        $to = trim(explode(',', $to, 2)[0]);
    }
    [, $email] = alttag_postmark_split_addr($to);
    return strtolower($email);
}

/**
 * If the site uses the alttag-registrations participant CPT, resolve the
 * recipient email to a participant ID so Postmark's dashboard can filter
 * emails per attendee without callers having to set X-* headers.
 *
 * Returns 0 when the CPT isn't registered, when nothing matches, or when
 * multiple participants share the same email (ambiguous → skip).
 */
function alttag_postmark_resolve_participant($recipient_email)
{
    if (!$recipient_email || !function_exists('post_type_exists') || !post_type_exists('participant')) {
        return 0;
    }
    $matches = get_posts([
        'post_type'   => 'participant',
        'post_status' => 'any',
        'numberposts' => 2,
        'fields'      => 'ids',
        'meta_query'  => [[
            'key'     => 'email',
            'value'   => $recipient_email,
            'compare' => '=',
        ]],
    ]);
    return (count($matches) === 1) ? (int) $matches[0] : 0;
}

/**
 * Coarse extension → MIME map for attachments. Ticket PDF + invoice PDF
 * are the common cases; anything else falls through to octet-stream so
 * Postmark still accepts the send (recipient's mail client sniffs the
 * real type from content).
 */
function alttag_postmark_mime_of($path)
{
    static $map = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'txt'  => 'text/plain',
        'html' => 'text/html',
        'csv'  => 'text/csv',
        'xml'  => 'application/xml',
        'zip'  => 'application/zip',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}
