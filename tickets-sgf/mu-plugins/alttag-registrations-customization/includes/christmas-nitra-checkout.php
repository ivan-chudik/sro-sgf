<?php

namespace Alttag\Registrations\Customization;

use Alttag\Registrations\PricingTiers;
use Alttag\Registrations\Selection\SelectionManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Christmas Nitra 2026 (xnt) - interactive checkout steps.
 *
 * The first pass replaced the standard day / participant pickers with a
 * read-only recap plus hidden inputs, which is what the client saw as "the
 * checkout does not look like the design": nothing visible, nothing
 * changeable. This file renders Roman's numbered-steps block in its place -
 * three steps (type, people, days) posting the same contract as the standard
 * pickers.
 *
 * What stays untouched behind the UI: SelectionManager::saveToSession still
 * rewrites the session from the posted form on every update_order_review, and
 * adjustPricing still computes people x tier[day_count]. This block only has
 * to keep posting the exact field names the standard pickers post:
 *
 *   selected_days[<product_id>][]            = <Y-m-d>
 *   selected_days_data[<product_id>][<Y-m-d>] = 1
 *   selected_participant_types[]              = 'person'
 *   selected_participant_types_data[person]   = <people>
 *
 * Steps 2 and 3 are pure form state: change a chip or the stepper, the hidden
 * inputs are rebuilt and `update_checkout` fires. Step 1 changes the product,
 * so it goes through xnt_ajax_switch_type() below, which swaps the cart line
 * and re-seeds the session before the same checkout update runs.
 *
 * Prices are never typed into the JS: window.XNT_CHECKOUT carries the active
 * pricing tier's per-day-count prices straight out of the product meta, so the
 * preview total can only ever show what adjustPricing will charge.
 */

const XNT_SWITCH_ACTION = 'xnt_switch_type';

const XNT_PRODUCTS = ['live' => ['sk' => 5963, 'en' => 5964], 'stream' => ['sk' => 5957, 'en' => 5958]];
const XNT_PARTICIPANT_TYPE = 'person';

/** The event's day numbers (1-4) in the order the product stores its dates. */
function xnt_available_dates(int $product_id): array
{
    $dates = get_post_meta($product_id, '_event_available_dates', true);
    if (!is_array($dates)) {
        return [];
    }

    $out = [];
    foreach ($dates as $row) {
        if (!empty($row['date'])) {
            $out[] = (string) $row['date'];
        }
    }
    return $out;
}

/**
 * Per-day-count prices of one pricing tier, falling back to the product's own
 * `_participant_types` tier map when the tier does not override them.
 */
function xnt_tier_prices(int $product_id, ?array $tier): array
{
    $types = get_post_meta($product_id, '_participant_types', true);
    $base = [];
    if (is_array($types)) {
        foreach ($types as $type) {
            if (($type['id'] ?? '') === XNT_PARTICIPANT_TYPE && is_array($type['tiers'] ?? null)) {
                $base = $type['tiers'];
                break;
            }
        }
    }

    $override = $tier['type_day_prices'][XNT_PARTICIPANT_TYPE] ?? [];
    $prices = [];
    foreach ([1, 2, 3, 4] as $days) {
        $value = $override[$days] ?? ($base[$days] ?? null);
        if ($value !== null) {
            $prices[$days] = round((float) $value, 2);
        }
    }
    return $prices;
}

/** The pricing tier with this key, or null. */
function xnt_tier_by_key(int $product_id, string $key): ?array
{
    foreach (PricingTiers::tiers($product_id) as $tier) {
        if (($tier['key'] ?? '') === $key) {
            return $tier;
        }
    }
    return null;
}

/** The page language this block switches its copy on. */
function xnt_current_language(): string
{
    $language = function_exists('pll_current_language') ? (string) pll_current_language() : '';
    return $language ?: 'sk';
}

/** True for any Christmas Nitra ticket, both types and both language mutations. */
function xnt_is_product(int $product_id): bool
{
    foreach (XNT_PRODUCTS as $by_language) {
        if (in_array($product_id, array_map('intval', $by_language), true)) {
            return true;
        }
    }
    return false;
}

/** 'live' or 'stream' for a Christmas Nitra product, '' for anything else. */
function xnt_product_type(int $product_id): string
{
    foreach (XNT_PRODUCTS as $type => $by_language) {
        if (in_array($product_id, array_map('intval', $by_language), true)) {
            return $type;
        }
    }
    return '';
}

/** Write the selection into the exact session keys SelectionManager reads. */
function xnt_seed_selection(int $product_id, array $days, int $people): void
{
    if (!function_exists('WC') || !WC()->session) {
        return;
    }

    $day_type = SelectionManager::getType('days');
    if ($day_type) {
        WC()->session->set($day_type->getSessionKey($product_id), $days);
        WC()->session->set($day_type->getDataSessionKey($product_id), array_fill_keys($days, 1));
    }

    $participant_type = SelectionManager::getType('participant_types');
    if ($participant_type) {
        WC()->session->set($participant_type->getSessionKey($product_id), [XNT_PARTICIPANT_TYPE]);
        WC()->session->set(
            $participant_type->getDataSessionKey($product_id),
            [XNT_PARTICIPANT_TYPE => $people]
        );
    }
}

/**
 * True when the cart holds nothing but Christmas Nitra tickets.
 *
 * Such a cart is driven entirely by this block, so the checkout must not offer
 * a second, conflicting day picker and people counter next to it.
 */
function xnt_cart_is_all_xnt(): bool
{
    if (!function_exists('WC') || !WC()->cart) {
        return false;
    }

    $items = WC()->cart->get_cart();
    if (empty($items)) {
        return false;
    }

    foreach ($items as $item) {
        if (!xnt_is_product((int) ($item['product_id'] ?? 0))) {
            return false;
        }
    }
    return true;
}

/** The seeded selection of one xnt line: ['days' => [...], 'people' => int]. */
function xnt_seeded_selection(int $product_id): array
{
    $day_type = SelectionManager::getType('days');
    $participant_type = SelectionManager::getType('participant_types');
    if (!$day_type || !$participant_type || !function_exists('WC') || !WC()->session) {
        return ['days' => [], 'people' => 0];
    }

    $days = $day_type->getFromSession($product_id);
    $data = $participant_type->getDataFromSession($product_id);

    return [
        'days' => is_array($days) ? array_values($days) : [],
        'people' => (int) ($data[XNT_PARTICIPANT_TYPE] ?? 0),
    ];
}

/**
 * Whether the checkout replaces the standard pickers with the xnt steps block.
 *
 * A seeded selection is not required: the block renders its own day chips and
 * people stepper, so a visitor who arrived on a bare ?add-to-cart (a restored
 * session) can still make and post a complete selection from it. Requiring a
 * seed here used to be right when the replacement was a read-only recap; with
 * the interactive block it would only put the old pickers back on the one
 * route that has no selection yet.
 */
function xnt_checkout_recap_applies(): bool
{
    return xnt_cart_is_all_xnt();
}

/**
 * Keep the chosen type on the order line.
 *
 * The days themselves are already stored by SelectionManager::saveToOrderItem
 * as `selected_days`, so only the hall / livestream distinction is added here -
 * and only when it is not already obvious from the product. The cart item data
 * it reads is set by xnt_ajax_switch_type().
 */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
    if (!empty($values['xnt_typ'])) {
        $item->add_meta_data('_xnt_typ', $values['xnt_typ'], true);
    }
}, 10, 3);

/** 'sk' / 'en' for a Christmas Nitra product, '' for anything else. */
function xnt_product_language(int $product_id): string
{
    foreach (XNT_PRODUCTS as $by_language) {
        foreach ($by_language as $language => $id) {
            if ((int) $id === $product_id) {
                return (string) $language;
            }
        }
    }
    return '';
}

/** The people cap the checkout enforces. */
function xnt_max_people(): int
{
    return max(1, (int) apply_filters('xnt_max_quantity', 10));
}

/**
 * Day chip label.
 *
 * The product's own `_event_available_dates` labels are Slovak, so English
 * gets a label built from the date instead ("Thursday 26 Nov").
 */
function xnt_day_label(string $date, string $language, string $stored_label = ''): string
{
    $time = strtotime($date . ' 12:00:00');
    if (!$time) {
        return $stored_label !== '' ? $stored_label : $date;
    }

    $weekday = xnt_checkout_text('wd' . (int) date('w', $time), $language);

    if ($language === 'en') {
        // The Slovak label below is numeric, so the month abbreviations are
        // only ever rendered in English and stay literal.
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return $weekday
            . ' ' . (int) date('j', $time)
            . ' ' . $months[(int) date('n', $time) - 1];
    }

    if ($stored_label !== '') {
        return $stored_label;
    }

    return $weekday . ' ' . (int) date('j', $time) . '. ' . (int) date('n', $time) . '.';
}

/** Per-person price for each day count 1-4 under the pricing tier in force now. */
function xnt_active_prices(int $product_id): array
{
    return xnt_tier_prices($product_id, PricingTiers::resolve($product_id));
}

/** Day dates plus their chip labels, in the product's own order. */
function xnt_day_options(int $product_id, string $language): array
{
    $day_type = SelectionManager::getType('days');
    $stored = $day_type ? $day_type->getAvailableOptions($product_id) : [];

    $options = [];
    foreach (xnt_available_dates($product_id) as $date) {
        $options[] = [
            'date' => $date,
            'label' => xnt_day_label($date, $language, (string) ($stored[$date] ?? '')),
        ];
    }
    return $options;
}

/** The catalog the block's copy lives in. */
const XNT_TEXT_DOMAIN = 'alttag-registrations-customization';

/**
 * One gettext lookup in an explicit locale.
 *
 * The block renders the *product's* language, which is not always the page's:
 * a Slovak page can carry the English product and the other way round, so the
 * ambient locale cannot be trusted. switch_to_locale() is no help either - the
 * bootstrap registers this catalog by path, and a locale switch leaves the
 * already loaded .mo in place (verified: __() keeps returning Slovak under
 * switch_to_locale('en_GB')). WP_Translation_Controller reads a named catalog
 * for a named locale without touching any global state, which is exactly what
 * this needs. English is the msgid language, so en_GB has no catalog at all
 * and every lookup falls through to the source string.
 */
function xnt_translate_in_locale(string $text, string $context, string $locale): string
{
    static $loaded = [];

    if (!isset($loaded[$locale])) {
        $file = ALTTAG_REGISTRATIONS_CUSTOMIZATION_PATH
            . '/languages/' . XNT_TEXT_DOMAIN . '-' . $locale . '.l10n.php';
        $loaded[$locale] = file_exists($file)
            && \WP_Translation_Controller::get_instance()->load_file($file, XNT_TEXT_DOMAIN, $locale);
    }

    if (!$loaded[$locale]) {
        return $text;
    }

    $translation = \WP_Translation_Controller::get_instance()
        ->translate($text, $context, XNT_TEXT_DOMAIN, $locale);

    return is_string($translation) && $translation !== '' ? $translation : $text;
}

/** The locale each of the block's two languages reads its copy from. */
function xnt_text_locale(string $language): string
{
    return $language === 'en' ? 'en_GB' : 'sk_SK';
}

/**
 * The whole copy map in one language.
 *
 * msgids are the English source strings and the visible Slovak comes out of
 * the catalog, per the project convention. Every entry carries a gettext
 * context derived from its key, because several keys share an English string
 * but not a Slovak one ('days' is both day1/dni and day2/dní, 'Livestream'
 * is both a step-1 option and a summary chip, 'Number of people' is both a
 * heading and an aria-label) and because the catalog already holds unrelated
 * entries for 'Total', 'Select days' and 'Your selection' that this block must
 * not pick up.
 *
 * The _x() calls are written out with literals so `wp i18n make-pot` still
 * finds them; the filter only redirects the lookup to the requested locale.
 */
function xnt_checkout_strings(string $language): array
{
    static $cache = [];

    $locale = xnt_text_locale($language);
    if (isset($cache[$locale])) {
        return $cache[$locale];
    }

    $in_locale = static function ($translation, $text, $context) use ($locale) {
        return xnt_translate_in_locale($text, $context, $locale);
    };
    add_filter('gettext_with_context_' . XNT_TEXT_DOMAIN, $in_locale, 99, 3);

    $strings = [
        // Step headers - Roman's block copy.
        'step1' => _x('How do you want to watch', 'xnt.step1', 'alttag-registrations-customization'),
        'step1sub' => _x(
            'The same price for the arena and the online broadcast',
            'xnt.step1sub',
            'alttag-registrations-customization'
        ),
        'step2' => _x('Number of people', 'xnt.step2', 'alttag-registrations-customization'),
        'step2sub' => _x(
            'Everyone gets the same ticket - same type and same days',
            'xnt.step2sub',
            'alttag-registrations-customization'
        ),
        'step3' => _x('Select days', 'xnt.step3', 'alttag-registrations-customization'),
        'step3sub' => _x(
            'Any combination - the days do not have to follow one another. The price recalculates instantly.',
            'xnt.step3sub',
            'alttag-registrations-customization'
        ),

        // Step 1 - ticket type.
        'segaria' => _x('Ticket type', 'xnt.segaria', 'alttag-registrations-customization'),
        'live' => _x('In-person in the arena', 'xnt.live', 'alttag-registrations-customization'),
        'livesub' => _x(
            'E-ticket with a QR code · valid all day',
            'xnt.livesub',
            'alttag-registrations-customization'
        ),
        'stream' => _x('Livestream', 'xnt.stream', 'alttag-registrations-customization'),
        'streamsub' => _x(
            'stream.sgf.sk · phone, laptop, TV',
            'xnt.streamsub',
            'alttag-registrations-customization'
        ),
        'fixedtype' => _x(
            'The ticket type can be changed in the configurator.',
            'xnt.fixedtype',
            'alttag-registrations-customization'
        ),
        'switchfailed' => _x(
            'The ticket type could not be changed. Please try again.',
            'xnt.switchfailed',
            'alttag-registrations-customization'
        ),

        // Step 2 - people.
        'qtyaria' => _x('Number of people', 'xnt.qtyaria', 'alttag-registrations-customization'),
        'minus' => _x('Fewer people', 'xnt.minus', 'alttag-registrations-customization'),
        'plus' => _x('More people', 'xnt.plus', 'alttag-registrations-customization'),
        'acc2' => _x(
            'Do you need different days or a different type for someone else? Finish this order and add another ticket in the cart.',
            'xnt.acc2',
            'alttag-registrations-customization'
        ),

        // Step 3 - days.
        'quickaria' => _x('Quick pick', 'xnt.quickaria', 'alttag-registrations-customization'),
        'quick3' => _x('Saturday finals', 'xnt.quick3', 'alttag-registrations-customization'),
        'quick34' => _x('Weekend · Sat + Sun', 'xnt.quick34', 'alttag-registrations-customization'),
        'quick23' => _x('Qualification + finals', 'xnt.quick23', 'alttag-registrations-customization'),
        'quickall' => _x('All 4 days · best value', 'xnt.quickall', 'alttag-registrations-customization'),
        'finals' => _x('Finals', 'xnt.finals', 'alttag-registrations-customization'),
        'acc3' => _x(
            'Competitors, coaches and accredited support-team members have entry included in their accreditation - they do not need a ticket.',
            'xnt.acc3',
            'alttag-registrations-customization'
        ),

        // Program line per day number, 1-4. <b> is the only markup Roman uses.
        'pg1' => _x(
            '<b>WG</b> training and podium training · <b>Open</b> individuals, day 1',
            'xnt.pg1',
            'alttag-registrations-customization'
        ),
        'pg2' => _x(
            '<b>WG</b> qualification · all-around seniors and juniors · <b>Open</b> individuals, day 2',
            'xnt.pg2',
            'alttag-registrations-customization'
        ),
        'pg3' => _x(
            '<b>WG</b> apparatus finals + victory ceremony · <b>Open</b> · <b>MSR</b> group routines',
            'xnt.pg3',
            'alttag-registrations-customization'
        ),
        'pg4' => _x(
            '<b>Open</b> group routines, duos and trios · <b>MSR</b> group routines + ceremony',
            'xnt.pg4',
            'alttag-registrations-customization'
        ),

        // The order review panel - Roman's "Tvoja vstupenka".
        'chipsaria' => _x('Your selection', 'xnt.chipsaria', 'alttag-registrations-customization'),
        'cta' => _x('Continue to payment', 'xnt.cta', 'alttag-registrations-customization'),
        'sumtitle' => _x('Your ticket', 'xnt.sumtitle', 'alttag-registrations-customization'),
        'total' => _x('Total', 'xnt.total', 'alttag-registrations-customization'),
        'vat' => _x('incl. VAT · prices are final', 'xnt.vat', 'alttag-registrations-customization'),
        'fine' => _x(
            'The ticket is valid for every competition block of the chosen day - you can leave and come back. Livestream access is active right after payment. Pay by card or Google Pay.',
            'xnt.fine',
            'alttag-registrations-customization'
        ),

        // The three explainer cards under the billing form, and the eyebrow
        // and heading above them - the same pair the landing page block runs
        // over its own copy of these cards.
        // %e / %n / %s / %p / %a are prices and %d / %f dates - every one of
        // them filled from the pricing tiers in xnt_info_values(), never
        // written into the copy.
        'infoeyebrow' => _x('Simply', 'xnt.infoeyebrow', 'alttag-registrations-customization'),
        'infohd' => _x('How the prices work', 'xnt.infohd', 'alttag-registrations-customization'),
        'info1h' => _x('Early Bird - until %d', 'xnt.info1h', 'alttag-registrations-customization'),
        'info1b' => _x(
            'Buy earlier and every day costs you <b>%e</b> instead of %n. From %f the full prices apply. The discount is applied automatically - no coupon, and it works the same for the arena and the livestream.',
            'xnt.info1b',
            'alttag-registrations-customization'
        ),
        'info2h' => _x('More days = cheaper', 'xnt.info2h', 'alttag-registrations-customization'),
        'info2b' => _x(
            '2 or 3 days: <b>%s</b> comes off the price. All 4 days: a pass for <b>%p</b> - cheaper than 3 days and %a a day. You combine the days freely.',
            'xnt.info2b',
            'alttag-registrations-customization'
        ),
        'info3h' => _x('Prices are per person', 'xnt.info3h', 'alttag-registrations-customization'),
        'info3b' => _x(
            'Every person needs their own ticket - you pick the number in <b>step 2</b> and the price is multiplied. The same prices for the arena and the livestream, final, with no extra fees.',
            'xnt.info3b',
            'alttag-registrations-customization'
        ),

        // The pricing table under the explainer cards - the columns of the
        // landing page's own .xnt-tbl. %d is the day Early Bird ends, %f the
        // first full-price day, %1 a price, all filled from the same tiers.
        'thdays' => _x('Number of days', 'xnt.thdays', 'alttag-registrations-customization'),
        'theb' => _x('Early Bird · until %d', 'xnt.theb', 'alttag-registrations-customization'),
        'thfull' => _x('From %f', 'xnt.thfull', 'alttag-registrations-customization'),
        'thsave' => _x('You save with Early Bird', 'xnt.thsave', 'alttag-registrations-customization'),
        'tbest' => _x('best value', 'xnt.tbest', 'alttag-registrations-customization'),
        'tperday' => _x('%1 per day', 'xnt.tperday', 'alttag-registrations-customization'),
        'tinstead' => _x('instead of %1', 'xnt.tinstead', 'alttag-registrations-customization'),
        'note' => _x(
            'Prices are per person, the same for in-person attendance and the livestream. Sales and payment via tickets.sgf.sk, the livestream is watched at stream.sgf.sk.',
            'xnt.note',
            'alttag-registrations-customization'
        ),

        // The event strip above the checkout form - the same opener as the
        // landing page. %1 is the day price, %d the Early Bird end date, %n
        // the counted days left on it.
        'eveyebrow' => _x(
            'Tickets · 26 – 29 November 2026 · Nitra City Sports Hall',
            'xnt.eveyebrow',
            'alttag-registrations-customization'
        ),
        'evtitle' => _x('Christmas Nitra', 'xnt.evtitle', 'alttag-registrations-customization'),
        'evsub' => _x(
            'Slovak Rhythmic Gymnastics Open',
            'xnt.evsub',
            'alttag-registrations-customization'
        ),
        'evday' => _x('Day from %1', 'xnt.evday', 'alttag-registrations-customization'),
        'evcheaper' => _x(
            'More days = <b>cheaper</b>',
            'xnt.evcheaper',
            'alttag-registrations-customization'
        ),
        'evsame' => _x(
            'Hall and livestream at the <b>same price</b>',
            'xnt.evsame',
            'alttag-registrations-customization'
        ),
        'ebuntil' => _x('until %d', 'xnt.ebuntil', 'alttag-registrations-customization'),
        'ebleft' => _x('%n left', 'xnt.ebleft', 'alttag-registrations-customization'),
        'ebended' => _x('ended %d', 'xnt.ebended', 'alttag-registrations-customization'),
        'ebfull' => _x('full prices apply', 'xnt.ebfull', 'alttag-registrations-customization'),

        // Strings the block builds in JS. %1 / %2 / %3 are prices, %p is the
        // per-person suffix, %n a day or people count.
        'typelive' => _x('In-person', 'xnt.typelive', 'alttag-registrations-customization'),
        'typestream' => _x('Livestream', 'xnt.typestream', 'alttag-registrations-customization'),
        'noday' => _x('Select at least one day', 'xnt.noday', 'alttag-registrations-customization'),
        'disc' => _x('Multi-day discount', 'xnt.disc', 'alttag-registrations-customization'),
        'discpass' => _x('Discount · %n-day pass', 'xnt.discpass', 'alttag-registrations-customization'),
        'perperson' => _x(' per person', 'xnt.perperson', 'alttag-registrations-customization'),
        'vatper' => _x('incl. VAT · %1 per person', 'xnt.vatper', 'alttag-registrations-customization'),
        'vatperday' => _x(' · %1 per day', 'xnt.vatperday', 'alttag-registrations-customization'),
        'vatday' => _x('incl. VAT · %1 per day', 'xnt.vatday', 'alttag-registrations-customization'),

        // .xnt-saved is the Early Bird line in Roman's block, not a multi-day
        // one: no days picked, Early Bird running, Early Bird over. %1/%2/%3
        // are the amounts, %d the day the tier flips (xnt.js render()).
        'ebtag' => _x('Early Bird', 'xnt.ebtag', 'alttag-registrations-customization'),
        'savedeb' => _x(
            '<b>Early Bird:</b> you save <b>%1</b> - from %d the same selection would cost %2.',
            'xnt.savedeb',
            'alttag-registrations-customization'
        ),
        'savedfull' => _x(
            'Early Bird ended %d - the full price applies. The multi-day discount still applies.',
            'xnt.savedfull',
            'alttag-registrations-customization'
        ),
        'hintone' => _x(
            'Each person needs their own ticket',
            'xnt.hintone',
            'alttag-registrations-customization'
        ),
        'hintmany' => _x(
            '%1 per person · each person gets their own ticket',
            'xnt.hintmany',
            'alttag-registrations-customization'
        ),
        'nudge0' => _x(
            'Select at least one day. Tip: <b>Saturday is finals day</b> - WG apparatus finals and the victory ceremony.',
            'xnt.nudge0',
            'alttag-registrations-customization'
        ),
        'nudge1' => _x(
            'Add a second day for only <b>+%1</b>%p - two days together %2%p. Or all 4 days for %3%p.',
            'xnt.nudge1',
            'alttag-registrations-customization'
        ),
        'nudge2' => _x(
            'All 4 days cost only <b>+%1</b>%p more (%2%p) - and you save %3%p vs individual days.',
            'xnt.nudge2',
            'alttag-registrations-customization'
        ),
        'nudge3' => _x(
            '<b>Four days are cheaper than three:</b> %1 instead of %2%p. Add the fourth day and <b>save %3%p</b>.',
            'xnt.nudge3',
            'alttag-registrations-customization'
        ),
        'nudge4' => _x(
            '<b>Best value.</b> The whole competition Thursday to Sunday for %1%p - that is %2 per day.',
            'xnt.nudge4',
            'alttag-registrations-customization'
        ),
        'btnsat' => _x('Select Saturday', 'xnt.btnsat', 'alttag-registrations-customization'),
        'btnall' => _x('All 4 days', 'xnt.btnall', 'alttag-registrations-customization'),
        'btnallsel' => _x('Select all 4 days', 'xnt.btnallsel', 'alttag-registrations-customization'),
        'btnfourth' => _x('Add 4th day', 'xnt.btnfourth', 'alttag-registrations-customization'),

        // Plural forms, index 0 = one, 1 = two to four, 2 = five and more.
        'day0' => _x('day', 'xnt.day0', 'alttag-registrations-customization'),
        'day1' => _x('days', 'xnt.day1', 'alttag-registrations-customization'),
        'day2' => _x('days', 'xnt.day2', 'alttag-registrations-customization'),
        'person0' => _x('person', 'xnt.person0', 'alttag-registrations-customization'),
        'person1' => _x('people', 'xnt.person1', 'alttag-registrations-customization'),
        'person2' => _x('people', 'xnt.person2', 'alttag-registrations-customization'),

        // Weekday names for the day-chip fallback label, index = date('w').
        'wd0' => _x('Sunday', 'xnt.wd0', 'alttag-registrations-customization'),
        'wd1' => _x('Monday', 'xnt.wd1', 'alttag-registrations-customization'),
        'wd2' => _x('Tuesday', 'xnt.wd2', 'alttag-registrations-customization'),
        'wd3' => _x('Wednesday', 'xnt.wd3', 'alttag-registrations-customization'),
        'wd4' => _x('Thursday', 'xnt.wd4', 'alttag-registrations-customization'),
        'wd5' => _x('Friday', 'xnt.wd5', 'alttag-registrations-customization'),
        'wd6' => _x('Saturday', 'xnt.wd6', 'alttag-registrations-customization'),
    ];

    remove_filter('gettext_with_context_' . XNT_TEXT_DOMAIN, $in_locale, 99);

    return $cache[$locale] = $strings;
}

/** Copy for one key in the page language - same raw-string style as checkout.php. */
function xnt_checkout_text(string $key, string $language): string
{
    return xnt_checkout_strings($language)[$key] ?? '';
}

/** The subset of xnt_checkout_text() the block has to build in the browser. */
const XNT_JS_TEXT_KEYS = [
    'noday', 'perperson', 'hintone', 'hintmany',
    'nudge0', 'nudge1', 'nudge2', 'nudge3', 'nudge4',
    'btnsat', 'btnall', 'btnallsel', 'btnfourth', 'switchfailed',
    'day0', 'day1', 'day2', 'person0', 'person1', 'person2',
];

/** The hidden selection fields for one line - the whole POST contract lives here. */
function xnt_selection_fields(int $product_id, array $days, int $people): string
{
    $day_type = SelectionManager::getType('days');
    $participant_type = SelectionManager::getType('participant_types');
    if (!$day_type) {
        return '';
    }

    $days_field = $day_type->getMetaKey() . '[' . $product_id . ']';
    $days_data_field = $day_type->getDataMetaKey() . '[' . $product_id . ']';

    $out = '';
    foreach ($days as $date) {
        $out .= '<input type="hidden" name="' . esc_attr($days_field) . '[]" value="' . esc_attr($date) . '">';
        $out .= '<input type="hidden" name="' . esc_attr($days_data_field) . '[' . esc_attr($date) . ']"'
            . ' value="1">';
    }

    if ($participant_type) {
        $out .= '<input type="hidden" name="' . esc_attr($participant_type->getMetaKey()) . '[]"'
            . ' value="' . esc_attr(XNT_PARTICIPANT_TYPE) . '">';
        $out .= '<input type="hidden"'
            . ' name="' . esc_attr($participant_type->getDataMetaKey())
            . '[' . esc_attr(XNT_PARTICIPANT_TYPE) . ']"'
            . ' value="' . esc_attr((string) $people) . '">';
    }

    return $out;
}

/** Roman's numbered step header: circle + title with a subtitle. */
function xnt_step_header(int $number, string $title, string $subtitle): string
{
    return '<div class="xnt-step">'
        . '<span class="xnt-n">' . esc_html((string) $number) . '</span>'
        . '<h2>' . esc_html($title)
        . '<small>' . esc_html($subtitle) . '</small>'
        . '</h2>'
        . '</div>';
}

/** The program line of one day - Roman's copy, <b> is the only markup in it. */
function xnt_day_program(int $number, string $language): string
{
    $copy = xnt_checkout_text('pg' . $number, $language);
    return $copy === '' ? '' : wp_kses($copy, ['b' => []]);
}

/** The checkbox tick Roman draws inside .xnt-box. */
function xnt_check_svg(): string
{
    return '<svg viewBox="0 0 16 16" fill="none" stroke="#fff" stroke-width="2.4"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M3 8.5l3 3 7-7"></path></svg>';
}

/** Step 1 - the type segment, interactive only when the swap has one line to act on. */
function xnt_step_type(string $current_type, bool $switchable, string $language): string
{
    $out = xnt_step_header(
        1,
        xnt_checkout_text('step1', $language),
        xnt_checkout_text('step1sub', $language)
    );

    $out .= '<div class="xnt-seg" role="radiogroup"'
        . ' aria-label="' . esc_attr(xnt_checkout_text('segaria', $language)) . '">';

    foreach (['live', 'stream'] as $type) {
        $is_on = $type === $current_type;
        if (!$switchable && !$is_on) {
            continue;
        }
        $out .= '<button type="button" class="xnt-co-type' . ($is_on ? ' on' : '') . '"'
            . ' data-xnt-type="' . esc_attr($type) . '"'
            . ' role="radio" aria-checked="' . ($is_on ? 'true' : 'false') . '"'
            . ($switchable ? '' : ' disabled') . '>'
            . '<i></i>'
            . '<span>'
            . '<b>' . esc_html(xnt_checkout_text($type, $language)) . '</b>'
            . '<span>' . esc_html(xnt_checkout_text($type . 'sub', $language)) . '</span>'
            . '</span>'
            . '</button>';
    }

    $out .= '</div>';
    if (!$switchable) {
        $out .= '<p class="xnt-acc">' . esc_html(xnt_checkout_text('fixedtype', $language)) . '</p>';
    }
    return $out;
}

/** Step 2 - the people stepper plus the "someone else needs other days" note. */
function xnt_step_people(int $people, string $language): string
{
    return xnt_step_header(
        2,
        xnt_checkout_text('step2', $language),
        xnt_checkout_text('step2sub', $language)
    )
        . '<div class="xnt-qty">'
        . '<div class="xnt-stepper" role="group"'
        . ' aria-label="' . esc_attr(xnt_checkout_text('qtyaria', $language)) . '">'
        . '<button type="button" data-xnt-people="-1"'
        . ' aria-label="' . esc_attr(xnt_checkout_text('minus', $language)) . '">&minus;</button>'
        . '<output class="xnt-co-people" aria-live="polite">' . esc_html((string) $people) . '</output>'
        . '<button type="button" data-xnt-people="1"'
        . ' aria-label="' . esc_attr(xnt_checkout_text('plus', $language)) . '">+</button>'
        . '</div>'
        . '<p class="xnt-qty-hint xnt-co-hint"></p>'
        . '</div>'
        . '<p class="xnt-acc">' . esc_html(xnt_checkout_text('acc2', $language)) . '</p>';
}

/** Step 3 (step 2 on livestream, which has no people step) - days. */
function xnt_step_days(array $options, array $days, string $language, int $step_number = 3): string
{
    $out = xnt_step_header(
        $step_number,
        xnt_checkout_text('step3', $language),
        xnt_checkout_text('step3sub', $language)
    );

    // The quick picks name specific days of this event, so they are only
    // offered when the product really carries the four days they refer to.
    if (count($options) === 4) {
        $picks = [
            '3' => 'quick3',
            '3,4' => 'quick34',
            '2,3' => 'quick23',
            '1,2,3,4' => 'quickall',
        ];
        $out .= '<div class="xnt-quick"'
            . ' aria-label="' . esc_attr(xnt_checkout_text('quickaria', $language)) . '">';
        foreach ($picks as $pick => $key) {
            $out .= '<button type="button"' . ($pick === '1,2,3,4' ? ' class="best"' : '')
                . ' data-pick="' . esc_attr($pick) . '">'
                . esc_html(xnt_checkout_text($key, $language)) . '</button>';
        }
        $out .= '</div>';
    }

    $out .= '<div class="xnt-days">';
    foreach ($options as $index => $option) {
        $number = $index + 1;
        $checked = in_array($option['date'], $days, true);
        $program = xnt_day_program($number, $language);

        $out .= '<label class="xnt-day' . ($checked ? ' on' : '') . '"'
            . ' data-i="' . esc_attr((string) $number) . '">'
            . '<input type="checkbox" data-xnt-day value="' . esc_attr($option['date']) . '"'
            . ($checked ? ' checked' : '') . '>'
            . '<span class="xnt-box">' . xnt_check_svg() . '</span>'
            . '<span>'
            . '<span class="xnt-nm">' . esc_html($option['label'])
            . ($number === 3 ? '<em>' . esc_html(xnt_checkout_text('finals', $language)) . '</em>' : '')
            . '</span>'
            . ($program !== '' ? '<span class="xnt-pg">' . $program . '</span>' : '')
            . '</span>'
            . '<span class="xnt-pr" data-pr></span>'
            . '</label>';
    }
    $out .= '</div>';

    $out .= '<div class="xnt-nudge" aria-live="polite">'
        . '<p class="xnt-co-nudge-t"></p>'
        . '<button type="button" class="xnt-co-nudge-b" hidden></button>'
        . '</div>';

    $out .= '<p class="xnt-acc">' . esc_html(xnt_checkout_text('acc3', $language)) . '</p>';

    return $out;
}

/**
 * The interactive steps block, one per Christmas Nitra cart line.
 *
 * Structure and class names are Roman's, so assets/styles/xnt.css styles the
 * block as delivered; xnt-checkout.css only adapts it to the width and the
 * surroundings of the checkout column. The parts of the design that belonged to
 * the sales page - the sticky mobile bar and the add-to-cart CTA - are not
 * rendered here: the checkout form has its own submit button and the cart is
 * already filled.
 *
 * The type switch is only offered when the cart holds a single xnt line: with
 * two of them the swap has no unambiguous line to replace, so the type shows
 * as a locked recap row instead of silently rewriting the wrong one.
 *
 * The visible UI and the hidden fields are built apart and concatenated by
 * xnt_render_checkout_fields() below. `data-xnt-index` is the nth cart line,
 * which is what pairs a block with its field container once they no longer
 * nest.
 */
function xnt_render_checkout_steps(): string
{
    $language = xnt_current_language();
    $items = WC()->cart->get_cart();
    $switchable = count($items) === 1;
    $max_people = xnt_max_people();

    $out = '<div class="xnt xnt-checkout xnt-co-steps-root">';
    $index = 0;
    foreach ($items as $item) {
        $product_id = (int) $item['product_id'];
        $selection = xnt_seeded_selection($product_id);
        $current_type = xnt_product_type($product_id) ?: 'live';
        $days = $selection['days'];
        $people = $current_type === 'stream'
            ? 1
            : max(1, min($max_people, (int) $selection['people']));
        $options = xnt_day_options($product_id, $language);

        $out .= '<div class="xnt-lay xnt-co" data-product-id="' . esc_attr((string) $product_id) . '"'
            . ' data-xnt-index="' . esc_attr((string) $index) . '"'
            . ' data-type="' . esc_attr($current_type) . '"'
            . ' data-switchable="' . ($switchable ? '1' : '0') . '">';

        // A livestream ticket is one account: no people step, days move to 2.
        $has_people = $current_type !== 'stream';

        $out .= '<div class="xnt-steps">'
            . '<section class="xnt-co-card">' . xnt_step_type($current_type, $switchable, $language) . '</section>'
            . ($has_people
                ? '<section class="xnt-co-card xnt-co-people-card">' . xnt_step_people($people, $language) . '</section>'
                : '')
            . '<section class="xnt-co-card xnt-co-days-card">'
            . xnt_step_days($options, $days, $language, $has_people ? 3 : 2)
            . '</section>'
            . '</div>';

        $out .= '</div>';
        $index++;
    }
    $out .= '</div>';

    return $out;
}

/**
 * What the selection-UI override hands back: the steps, then the hidden inputs.
 *
 * Both halves land where the shared plugin puts the selection UI - inside
 * <form class="checkout">, at the top of the billing column, above the billing
 * fields. That position is the requirement: the order-review column has to
 * start level with step 1, which it cannot do while anything sits full width
 * above the form.
 *
 * The hidden inputs must be inside that form either way - anything outside it
 * is simply not posted, and the POST is the whole contract with
 * SelectionManager::saveToSession.
 *
 * The billing column measures ~700px (Elementor lays the form out as
 * `grid-template-columns: 56% auto` in a 1250px container), which the steps
 * fit: the vendor two-column `.xnt-lay` is overridden to a single column in
 * xnt-checkout.css, because the summary half of it is the order review now.
 */
function xnt_render_checkout_fields(): string
{
    $max_people = xnt_max_people();

    $out = xnt_render_checkout_steps();
    $index = 0;
    foreach (WC()->cart->get_cart() as $item) {
        $product_id = (int) $item['product_id'];
        $selection = xnt_seeded_selection($product_id);
        $people = xnt_product_type($product_id) === 'stream'
            ? 1
            : max(1, min($max_people, (int) $selection['people']));

        $out .= '<div class="xnt-co-fields" data-xnt-index="' . esc_attr((string) $index) . '">'
            . xnt_selection_fields($product_id, $selection['days'], $people)
            . '</div>';
        $index++;
    }

    return $out;
}

/**
 * Hand the shared plugin this block instead of its own pickers.
 * Returning a string also stands down the selection JS config and assets, so
 * nothing is left looking for markup that is no longer there.
 *
 * The steps and the hidden inputs both come back from here, so they land at
 * the top of the billing column and the order-review column starts level with
 * step 1.
 */
add_filter('alttag_registrations_checkout_selection_ui', function ($markup) {
    if ($markup !== null || !xnt_checkout_recap_applies()) {
        return $markup;
    }

    return xnt_render_checkout_fields();
});

/**
 * window.XNT_CHECKOUT - prices, dates and copy for both types, so a swap needs
 * no reload and no number is ever typed into the JS.
 *
 * `money` is WooCommerce's own price format, so the preview total is printed
 * exactly the way the order review next to it prints the same amount.
 */
function xnt_checkout_config(string $language): array
{
    $types = [];
    foreach (XNT_PRODUCTS as $type => $by_language) {
        $product_id = (int) ($by_language[$language] ?? $by_language['sk']);
        $types[$type] = [
            'id' => $product_id,
            'days' => xnt_day_options($product_id, $language),
            'prices' => xnt_active_prices($product_id),
            // The undiscounted tier, for the struck-through per-day price the
            // landing block shows while Early Bird is running.
            'pricesFull' => xnt_tier_prices($product_id, xnt_tier_by_key($product_id, 'normalna-cena')),
        ];
    }

    $text = [];
    foreach (XNT_JS_TEXT_KEYS as $key) {
        $text[$key] = xnt_checkout_text($key, $language);
    }

    return [
        'lang' => $language,
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'action' => XNT_SWITCH_ACTION,
        'nonce' => wp_create_nonce(XNT_SWITCH_ACTION),
        'maxPeople' => xnt_max_people(),
        'types' => $types,
        'money' => [
            'symbol' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
            'format' => html_entity_decode(get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8'),
            'decimals' => wc_get_price_decimals(),
            'decimalSep' => wc_get_price_decimal_separator(),
            'thousandSep' => wc_get_price_thousand_separator(),
        ],
        'text' => $text,
        'fields' => [
            'days' => 'selected_days',
            'daysData' => 'selected_days_data',
            'participants' => 'selected_participant_types',
            'participantsData' => 'selected_participant_types_data',
            'participantType' => XNT_PARTICIPANT_TYPE,
        ],
    ];
}

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
        return;
    }
    if (!xnt_checkout_recap_applies()) {
        return;
    }

    $base_path = ALTTAG_REGISTRATIONS_CUSTOMIZATION_PATH . '/assets/';
    $base_url = ALTTAG_REGISTRATIONS_CUSTOMIZATION_URL . 'assets/';

    // Roman's own stylesheet does the design; xnt-checkout.css loads after it
    // and only adapts the block to the checkout column.
    wp_enqueue_style('xnt', $base_url . 'styles/xnt.css', [], filemtime($base_path . 'styles/xnt.css'));
    wp_enqueue_style(
        'xnt-checkout',
        $base_url . 'styles/xnt-checkout.css',
        ['xnt'],
        filemtime($base_path . 'styles/xnt-checkout.css')
    );
    wp_enqueue_script(
        'xnt-checkout',
        $base_url . 'js/xnt-checkout.js',
        ['jquery'],
        filemtime($base_path . 'js/xnt-checkout.js'),
        true
    );
    wp_add_inline_script(
        'xnt-checkout',
        'window.XNT_CHECKOUT = ' . wp_json_encode(xnt_checkout_config(xnt_current_language())) . ';',
        'before'
    );
});

/**
 * Step 1: swap the cart line for the other type.
 *
 * Days travel as indexes into the product's own date order, because the two
 * products store their own `_event_available_dates` rows - the same index is
 * the same calendar day, the date string is not guaranteed to be.
 *
 * The replacement is added before the old line is removed, so a product that
 * cannot be bought right now (PricingTiers closed the window, stock ran out)
 * leaves the cart exactly as it was instead of emptying it.
 */
function xnt_ajax_switch_type(): void
{
    check_ajax_referer(XNT_SWITCH_ACTION, 'nonce');

    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => 'no-cart']);
    }

    $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
    if (!isset(XNT_PRODUCTS[$type])) {
        wp_send_json_error(['message' => 'bad-type']);
    }

    $people = isset($_POST['people']) ? absint(wp_unslash($_POST['people'])) : 1;
    $people = $type === 'stream' ? 1 : max(1, min(xnt_max_people(), $people));

    $indexes = [];
    if (isset($_POST['days']) && is_array($_POST['days'])) {
        foreach (wp_unslash($_POST['days']) as $index) {
            $indexes[] = absint($index);
        }
    }

    $language = '';
    $existing = [];
    foreach (WC()->cart->get_cart() as $key => $item) {
        $product_id = (int) ($item['product_id'] ?? 0);
        if (!xnt_is_product($product_id)) {
            continue;
        }
        $existing[] = $key;
        if ($language === '') {
            $language = xnt_product_language($product_id);
        }
    }

    $language = $language ?: xnt_current_language();
    $target = (int) (XNT_PRODUCTS[$type][$language] ?? XNT_PRODUCTS[$type]['sk']);

    $dates = xnt_available_dates($target);
    $days = [];
    foreach ($indexes as $index) {
        if (isset($dates[$index]) && !in_array($dates[$index], $days, true)) {
            $days[] = $dates[$index];
        }
    }
    if (!$days && $dates) {
        $days[] = $dates[0];
    }

    $new_key = WC()->cart->add_to_cart($target, 1, 0, [], ['xnt_typ' => $type]);
    if (!$new_key) {
        wp_send_json_error(['message' => 'add-failed']);
    }

    foreach ($existing as $key) {
        if ($key !== $new_key) {
            WC()->cart->remove_cart_item($key);
        }
    }

    // The checkout update that follows re-posts the form and overwrites these,
    // but seeding now keeps the line priced if that request never lands.
    xnt_seed_selection($target, $days, $people);
    WC()->cart->calculate_totals();

    wp_send_json_success([
        'productId' => $target,
        'type' => $type,
        'days' => $days,
        'people' => $people,
        // The block's own shape changes with the type (a livestream line has
        // no people step and renumbers the days), and update_order_review does
        // not re-render the billing column - so the new steps travel here and
        // the script swaps the whole root.
        'steps' => xnt_render_checkout_steps(),
    ]);
}

add_action('wp_ajax_' . XNT_SWITCH_ACTION, __NAMESPACE__ . '\\xnt_ajax_switch_type');
add_action('wp_ajax_nopriv_' . XNT_SWITCH_ACTION, __NAMESPACE__ . '\\xnt_ajax_switch_type');

// =============================================================================
// The order review column, dressed as Roman's "Tvoja vstupenka" panel
// =============================================================================
//
// The bespoke summary aside that used to sit beside the steps is gone: it was
// a second, parallel total next to the one WooCommerce already prints, which is
// exactly what the client objected to. The design now lands on the markup the
// checkout renders anyway - #order_review, the review order table, the payment
// box and the place-order button - restyled in xnt-checkout.css.
//
// Only what CSS genuinely cannot produce is added here, and all of it through
// WooCommerce's own extension points, so a Woo update keeps rendering the
// table: the day/type chips come from woocommerce_get_item_data, the base,
// discount, total and Early Bird rows from the two review-order tfoot hooks,
// the panel title and the CTA label from the string filters. The native
// subtotal and total rows are left in the markup and hidden in CSS - they are
// the same numbers, printed in a shape the design does not use.

/** True on a checkout page whose cart is the one this block owns. */
function xnt_review_applies(): bool
{
    return function_exists('is_checkout')
        && is_checkout()
        && !is_order_received_page()
        && xnt_checkout_recap_applies();
}

/**
 * wc_price() as plain text, for the places WooCommerce escapes the string.
 *
 * Rounded first: the per-person and per-day figures are divisions, and
 * number_format() truncates 6.374999... to 6,37 where the visitor divides
 * 12,75 by 2 and gets 6,38.
 */
function xnt_plain_price(float $amount): string
{
    $amount = round($amount, wc_get_price_decimals());
    return trim(html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8'));
}

/**
 * What the panel has to state that the table does not: the undiscounted base,
 * the multi-day discount and the Early Bird comparison.
 *
 * Every number comes from the same two places the charge does - the cart line
 * totals and the pricing tiers - so the panel cannot quote a price the order
 * will not have. Returns [] when the cart holds no priced xnt line.
 */
function xnt_review_summary(): array
{
    if (!function_exists('WC') || !WC()->cart) {
        return [];
    }

    $people = 0;
    $show_people = false;
    $day_count = 0;
    $unit = 0.0;
    $line_total = 0.0;
    $full_total = 0.0;

    foreach (WC()->cart->get_cart() as $item) {
        $product_id = (int) ($item['product_id'] ?? 0);
        if (!xnt_is_product($product_id)) {
            continue;
        }

        $selection = xnt_seeded_selection($product_id);
        $days = count($selection['days']);
        $line_people = xnt_product_type($product_id) === 'stream'
            ? 1
            : max(1, (int) $selection['people']);
        if (!$days) {
            continue;
        }

        $active = xnt_active_prices($product_id);
        $full = xnt_tier_prices($product_id, xnt_tier_by_key($product_id, 'normalna-cena'));

        // Display totals: the shop prices include VAT, so the row the visitor
        // compares this against is line_subtotal + its tax.
        $line_total += (float) ($item['line_subtotal'] ?? 0) + (float) ($item['line_subtotal_tax'] ?? 0);
        $full_total += (float) ($full[$days] ?? $active[$days] ?? 0) * $line_people;

        $people += $line_people;
        $show_people = $show_people || xnt_product_type($product_id) !== 'stream';
        $day_count = max($day_count, $days);
        $unit = max($unit, (float) ($active[1] ?? 0));
    }

    if ($line_total <= 0 || !$day_count) {
        return [];
    }

    // The line total is a sum of a net amount and its tax, so it arrives as
    // 25.4999...; the per-person and per-day figures are divisions of it and
    // would round a cent short of the total printed right above them.
    $line_total = round($line_total, wc_get_price_decimals());
    $base = $unit * $day_count * $people;

    return [
        'people' => $people,
        'showPeople' => $show_people,
        'days' => $day_count,
        'unit' => $unit,
        'base' => $base,
        'total' => $line_total,
        'discount' => max(0.0, $base - $line_total),
        'fullTotal' => $full_total,
        // Early Bird is running when the tier in force undercuts the full one -
        // the same test the landing block's struck-through price uses.
        'earlyBird' => $full_total > $line_total + 0.005,
    ];
}

/** Slovak has three plural forms (1 / 2-4 / 5+); English collapses the last two. */
function xnt_plural(int $count, string $stem, string $language): string
{
    $index = $count === 1 ? 0 : ($count >= 2 && $count <= 4 ? 1 : 2);
    return xnt_checkout_text($stem . $index, $language);
}

/** "2 osoby", "3 dni" - the counted noun the panel rows are written with. */
function xnt_counted(int $count, string $stem, string $language): string
{
    return $count . ' ' . xnt_plural($count, $stem, $language);
}

/** Fill the %1 / %2 / %n / %p placeholders of the copy map. */
function xnt_fill(string $template, array $values): string
{
    return str_replace(array_keys($values), array_values($values), $template);
}

/**
 * The chips row of the panel: ticket type, head count, then the chosen days.
 *
 * This replaces the shared plugin's own "Vyberte dni / Účastníci" rows for xnt
 * lines rather than adding to them - the two said the same thing twice, and the
 * plugin's day labels come out of the Slovak product meta even on /en/.
 */
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
    $product_id = (int) ($cart_item['product_id'] ?? 0);
    if (!xnt_review_applies() || !xnt_is_product($product_id)) {
        return $item_data;
    }

    $language = xnt_current_language();
    $selection = xnt_seeded_selection($product_id);
    if (!$selection['days']) {
        return $item_data;
    }

    $type = xnt_product_type($product_id) ?: 'live';
    $people = max(1, (int) $selection['people']);

    $chips = '<span class="xnt-chip xnt-chip-type">'
        . esc_html(xnt_checkout_text($type === 'stream' ? 'typestream' : 'typelive', $language))
        . '</span>';

    // A livestream line is one account, so it carries no head-count chip.
    if ($type !== 'stream') {
        $chips .= '<span class="xnt-chip xnt-chip-type">'
            . esc_html(xnt_counted($people, 'person', $language))
            . '</span>';
    }

    // Walked in the product's own date order, not the session's: the session
    // keeps the order the days were ticked in, and "Fri, Sat, Sun" is not it.
    foreach (xnt_day_options($product_id, $language) as $option) {
        if (in_array($option['date'], $selection['days'], true)) {
            $chips .= '<span class="xnt-chip">' . esc_html($option['label']) . '</span>';
        }
    }

    return [[
        'key' => xnt_checkout_text('chipsaria', $language),
        'value' => '<span class="xnt-chips-row">' . $chips . '</span>',
    ]];
}, 50, 2);

/**
 * Drop WooCommerce's "× 1" after the product name on xnt lines.
 *
 * The cart quantity is always 1 there - the head count is a selection, not the
 * line quantity - so the suffix sat next to a "2 OSOBY" chip saying the
 * opposite. The chips row carries the count.
 */
add_filter('woocommerce_checkout_cart_item_quantity', function ($html, $cart_item) {
    if (!xnt_review_applies() || !xnt_is_product((int) ($cart_item['product_id'] ?? 0))) {
        return $html;
    }
    return '';
}, 50, 2);

/** The base and multi-day-discount rows, above the table's own total row. */
add_action('woocommerce_review_order_before_order_total', function () {
    if (!xnt_review_applies()) {
        return;
    }

    $summary = xnt_review_summary();
    if (!$summary) {
        return;
    }

    $language = xnt_current_language();
    $key = (empty($summary['showPeople'])
        ? ''
        : xnt_counted($summary['people'], 'person', $language) . ' × ')
        . xnt_counted($summary['days'], 'day', $language)
        . ' · ' . ($summary['earlyBird'] ? xnt_checkout_text('ebtag', $language) . ' ' : '')
        . xnt_plain_price($summary['unit']);

    echo '<tr class="xnt-line-row"><th class="xnt-line-k">' . esc_html($key) . '</th>'
        . '<td class="xnt-line-v">' . wp_kses_post(wc_price($summary['base'])) . '</td></tr>';

    if ($summary['discount'] <= 0.005) {
        return;
    }

    $discount_key = $summary['days'] >= 4
        ? xnt_fill(xnt_checkout_text('discpass', $language), ['%n' => (string) $summary['days']])
        : xnt_checkout_text('disc', $language);

    echo '<tr class="xnt-line-row xnt-line-discount">'
        . '<th class="xnt-line-k">' . esc_html($discount_key) . '</th>'
        . '<td class="xnt-line-v">&minus;' . wp_kses_post(wc_price($summary['discount'])) . '</td></tr>';
}, 20);

/** The SPOLU row, the Early Bird box and the footnote, below the table's own total. */
add_action('woocommerce_review_order_after_order_total', function () {
    if (!xnt_review_applies()) {
        return;
    }

    $summary = xnt_review_summary();
    if (!$summary) {
        return;
    }

    $language = xnt_current_language();
    $people = $summary['people'];
    $days = $summary['days'];
    $total = $summary['total'];

    $sub = xnt_checkout_text('vat', $language);
    if ($people > 1) {
        $sub = xnt_fill(xnt_checkout_text('vatper', $language), ['%1' => xnt_plain_price($total / $people)])
            . ($days > 1
                ? xnt_fill(
                    xnt_checkout_text('vatperday', $language),
                    ['%1' => xnt_plain_price($total / $people / $days)]
                )
                : '');
    } elseif ($days > 1) {
        $sub = xnt_fill(xnt_checkout_text('vatday', $language), ['%1' => xnt_plain_price($total / $days)]);
    }

    // One cell, not two: the 40px total is the widest thing in the price
    // column, and as a column cell it pushed every row's price 146px in from
    // the right - it took the product name down to 60% of the table. The flex
    // row lives on the inner .xnt-total-i wrapper, not on the td: display:flex
    // on the cell itself stops it being a table-cell, colspan is then ignored
    // and the row collapses back into column 1. With the td a plain cell the
    // span works, the price column shrinks to its own amounts, and the label
    // and the amount share a baseline instead of the amount floating in the
    // middle of the three-line subline.
    echo '<tr class="xnt-total-row"><td class="xnt-total-c" colspan="2"><span class="xnt-total-i">'
        . '<span class="xnt-total-k">' . esc_html(xnt_checkout_text('total', $language))
        . '<small class="xnt-total-sub">' . esc_html($sub) . '</small></span>'
        . '<span class="xnt-total-val">'
        . wp_kses_post(wc_price($total)) . '</span></span></td></tr>';

    $saved = $summary['earlyBird']
        ? xnt_fill(xnt_checkout_text('savedeb', $language), [
            '%1' => xnt_plain_price($summary['fullTotal'] - $total),
            '%2' => xnt_plain_price($summary['fullTotal']),
            '%d' => xnt_review_full_from_label($language),
        ])
        : xnt_fill(xnt_checkout_text('savedfull', $language), ['%d' => xnt_review_eb_end_label($language)]);

    echo '<tr class="xnt-saved-row"><td colspan="2">'
        . '<p class="xnt-saved-box' . ($summary['earlyBird'] ? '' : ' off') . '">'
        . wp_kses($saved, ['b' => []]) . '</p>'
        . '<p class="xnt-fineprint">' . esc_html(xnt_checkout_text('fine', $language)) . '</p>'
        . '</td></tr>';
}, 20);

/**
 * A short date in the page language - "15. 10." on Slovak, "15 Oct" on
 * English, the same shape xnt_day_label() gives the day chips.
 */
function xnt_short_date(int $time, string $language, bool $with_year = false): string
{
    if ($language === 'en') {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $out = (int) date('j', $time) . ' ' . $months[(int) date('n', $time) - 1];
        return $with_year ? $out . ' ' . date('Y', $time) : $out;
    }

    $out = (int) date('j', $time) . '. ' . (int) date('n', $time) . '.';
    return $with_year ? $out . ' ' . date('Y', $time) : $out;
}

/** Noon on the Early Bird tier's last day, or 0 when the tier has no end. */
function xnt_eb_end_time(string $language): int
{
    $product_id = (int) (XNT_PRODUCTS['live'][$language] ?? XNT_PRODUCTS['live']['sk']);
    $tier = xnt_tier_by_key($product_id, 'early-bird');
    return (int) strtotime(($tier['date_to'] ?? '') . ' 12:00:00');
}

/** The day the Early Bird tier ends. */
function xnt_review_eb_end_label(string $language): string
{
    $time = xnt_eb_end_time($language);
    return $time ? xnt_short_date($time, $language) : '';
}

/** The first day the full price applies - the tier's last day is inclusive. */
function xnt_review_full_from_label(string $language): string
{
    $time = xnt_eb_end_time($language);
    return $time ? xnt_short_date((int) strtotime('+1 day', $time), $language) : '';
}

/**
 * The panel title and the CTA label.
 *
 * WooCommerce prints "Your order" straight from the template, with no filter of
 * its own, so the heading is caught on gettext - matched on the English source
 * string and the woocommerce domain, never on the translated one. The button
 * has a proper filter; it carries the total because Woo re-renders
 * .woocommerce-checkout-payment on every update_order_review, so the amount on
 * it stays the amount below it.
 */
add_filter('gettext', function ($translated, $text, $domain) {
    if ($domain !== 'woocommerce' || $text !== 'Your order' || !xnt_review_applies()) {
        return $translated;
    }
    return xnt_checkout_text('sumtitle', xnt_current_language());
}, 30, 3);

add_filter('woocommerce_order_button_text', function ($text) {
    if (!xnt_review_applies()) {
        return $text;
    }

    $label = xnt_checkout_text('cta', xnt_current_language());
    $summary = xnt_review_summary();

    return $summary ? $label . ' · ' . xnt_plain_price($summary['total']) : $label;
}, 20);

/**
 * The "how the prices work" heading and the three explainer cards, under the
 * billing form and the order summary, spanning the full checkout width.
 *
 * Elementor's checkout widget closes the whole .e-checkout__container on
 * woocommerce_checkout_after_order_review at priority 95, so running at 96
 * renders this block below the two-column layout as a sibling of the
 * container: full width under both the billing column and the order summary,
 * aligned to the container's left edge. They are static copy, so they are
 * echoed as markup and styled in xnt-checkout.css; nothing about them changes
 * on an update. The .xnt-info-block wrapper keeps the three of them one unit.
 */
add_action('woocommerce_checkout_after_order_review', function () {
    if (!xnt_review_applies()) {
        return;
    }

    $language = xnt_current_language();
    $values = xnt_info_values($language);

    echo '<div class="xnt-info-block">';
    echo '<p class="xnt-info-eyebrow">'
        . esc_html(xnt_checkout_text('infoeyebrow', $language)) . '</p>'
        . '<h2 class="xnt-info-hd">'
        . esc_html(xnt_checkout_text('infohd', $language)) . '</h2>';

    echo '<div class="xnt-info">';
    foreach ([1, 2, 3] as $number) {
        echo '<section class="xnt-info-card">'
            . '<span class="xnt-info-n">0' . (int) $number . '</span>'
            . '<h3 class="xnt-info-h">'
            . esc_html(xnt_fill(xnt_checkout_text('info' . $number . 'h', $language), $values)) . '</h3>'
            . '<p class="xnt-info-b">'
            . wp_kses(xnt_fill(xnt_checkout_text('info' . $number . 'b', $language), $values), ['b' => []])
            . '</p>'
            . '</section>';
    }
    echo '</div>' . xnt_render_price_table($language) . '</div>';
}, 96);

/** Scope for the CSS that restyles markup outside the block's own .xnt root. */
add_filter('body_class', function ($classes) {
    if (xnt_review_applies()) {
        $classes[] = 'xnt-co-page';
    }
    return $classes;
});

/**
 * The prices and dates the three explainer cards state.
 *
 * All of it from the in-person product's pricing tiers - the cards claim the
 * two types cost the same, so quoting one of them is quoting both. Missing
 * tiers leave the token empty rather than printing a made-up figure.
 */
function xnt_info_values(string $language): array
{
    $product_id = (int) (XNT_PRODUCTS['live'][$language] ?? XNT_PRODUCTS['live']['sk']);
    $early = xnt_tier_prices($product_id, xnt_tier_by_key($product_id, 'early-bird'));
    $full = xnt_tier_prices($product_id, xnt_tier_by_key($product_id, 'normalna-cena'));

    $day = (float) ($early[1] ?? 0);
    $pass = (float) ($early[4] ?? 0);
    // The multi-day discount is what two days cost less than two single days.
    $saving = max(0.0, $day * 2 - (float) ($early[2] ?? $day * 2));

    $end = xnt_eb_end_time($language);

    return [
        '%e' => $day ? xnt_plain_price($day) : '',
        '%n' => isset($full[1]) ? xnt_plain_price((float) $full[1]) : '',
        '%s' => $saving ? xnt_plain_price($saving) : '',
        '%p' => $pass ? xnt_plain_price($pass) : '',
        '%a' => $pass ? xnt_plain_price($pass / 4) : '',
        '%d' => $end ? xnt_short_date($end, $language, true) : '',
        '%f' => xnt_review_full_from_label($language),
    ];
}

/**
 * The pricing table under the explainer cards, plus its footnote.
 *
 * Columns, class names and the best-value row are the landing page's .xnt-tbl,
 * and the two tiers behind it are the ones xnt_info_values() quotes right
 * above - there is no second price list anywhere. It is rendered server side
 * because the vendor xnt.js that fills #xnt-ptable on the landing needs the
 * whole configurator around it and is not loaded here. A missing tier drops
 * the row rather than printing a made-up figure; no rows means no table.
 */
function xnt_render_price_table(string $language): string
{
    $product_id = (int) (XNT_PRODUCTS['live'][$language] ?? XNT_PRODUCTS['live']['sk']);
    $eb = xnt_tier_prices($product_id, xnt_tier_by_key($product_id, 'early-bird'));
    $full = xnt_tier_prices($product_id, xnt_tier_by_key($product_id, 'normalna-cena'));

    $dates = [
        '%d' => xnt_review_eb_end_label($language),
        '%f' => xnt_review_full_from_label($language),
    ];

    $rows = '';
    foreach ([1, 2, 3, 4] as $days) {
        if (!isset($eb[$days], $full[$days])) {
            continue;
        }
        $eb_price = (float) $eb[$days];
        $full_price = (float) $full[$days];
        // Every multi-day row states what it works out at per day and what the
        // same days would cost bought one by one; the 4-day pass is the one
        // the design pills as the best value.
        $per_day = $days > 1
            ? esc_html(xnt_fill(
                xnt_checkout_text('tperday', $language),
                ['%1' => xnt_plain_price($eb_price / $days)]
            ))
            : '&nbsp;';
        $instead = $days > 1 && isset($eb[1])
            ? '<span class="per">' . esc_html(xnt_fill(
                xnt_checkout_text('tinstead', $language),
                ['%1' => xnt_plain_price((float) $eb[1] * $days)]
            )) . '</span>'
            : '';
        $rows .= '<tr' . ($days === 4 ? ' class="best"' : '') . '>'
            . '<td class="n">' . esc_html(xnt_counted($days, 'day', $language))
            . ($days === 4 ? '<em>' . esc_html(xnt_checkout_text('tbest', $language)) . '</em>' : '')
            . '<span class="per">' . $per_day . '</span></td>'
            . '<td class="ebp">' . esc_html(xnt_plain_price($eb_price)) . $instead . '</td>'
            . '<td class="full">' . esc_html(xnt_plain_price($full_price)) . '</td>'
            . '<td class="save">−' . esc_html(xnt_plain_price($full_price - $eb_price)) . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        return '';
    }

    return '<div class="xnt xnt-checkout xnt-co-pricing">'
        . '<div class="xnt-tbl"><table><thead><tr>'
        . '<th>' . esc_html(xnt_checkout_text('thdays', $language)) . '</th>'
        . '<th>' . esc_html(xnt_fill(xnt_checkout_text('theb', $language), $dates)) . '</th>'
        . '<th>' . esc_html(xnt_fill(xnt_checkout_text('thfull', $language), $dates)) . '</th>'
        . '<th>' . esc_html(xnt_checkout_text('thsave', $language)) . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="xnt-note">' . esc_html(xnt_checkout_text('note', $language)) . '</p>'
        . '</div>';
}

/**
 * Whole days left on the Early Bird tier, 0 once it is over.
 *
 * xnt_eb_end_time() is noon on the tier's last day and that day is inclusive,
 * so the deadline is the end of it - the same boundary the landing block's
 * countdown uses (cfg.ebEnd is that day at 23:59:59).
 */
function xnt_eb_days_left(string $language): int
{
    $end = xnt_eb_end_time($language);
    if (!$end) {
        return 0;
    }
    $deadline = (int) strtotime('tomorrow', $end) - 1;
    return max(0, (int) ceil(($deadline - time()) / DAY_IN_SECONDS));
}

/**
 * The Early Bird pill: the deadline over the days left while the tier runs,
 * the ended state after it. Computed per render, so the count is never stale.
 * '' when the tier carries no end date and there is nothing to count down to.
 */
function xnt_render_eb_pill(string $language): string
{
    $end = xnt_eb_end_time($language);
    if (!$end) {
        return '';
    }

    $label = xnt_short_date($end, $language, true);
    $left = xnt_eb_days_left($language);
    $over = $left < 1;

    $deadline = $over
        ? xnt_fill(xnt_checkout_text('ebended', $language), ['%d' => $label])
        : xnt_fill(xnt_checkout_text('ebuntil', $language), ['%d' => $label]);
    $countdown = $over
        ? xnt_checkout_text('ebfull', $language)
        : xnt_fill(
            xnt_checkout_text('ebleft', $language),
            ['%n' => xnt_counted($left, 'day', $language)]
        );

    return '<div class="xnt-eb' . ($over ? ' off' : '') . '">'
        . '<span class="xnt-eb-t">' . esc_html(xnt_checkout_text('ebtag', $language)) . '</span>'
        . '<span class="xnt-eb-d">'
        . '<span>' . esc_html($deadline) . '</span>'
        . '<b>' . esc_html($countdown) . '</b>'
        . '</span></div>';
}

/**
 * The event strip that opens the checkout.
 *
 * Roman's .xnt-ev markup, so xnt.css styles this as delivered - hence the .xnt
 * root, which every one of those rules is scoped under. The day price is the
 * tier in force with the full one struck through beside it while Early Bird
 * runs; that <del> is written out server-side and styled by the
 * .xnt-checkout .xnt-ev-meta .xnt-ov-del rule in xnt-checkout.css.
 */
function xnt_render_banner(string $language): string
{
    $product_id = (int) (XNT_PRODUCTS['live'][$language] ?? XNT_PRODUCTS['live']['sk']);
    $day = (float) (xnt_active_prices($product_id)[1] ?? 0);
    $day_full = (float) (xnt_tier_prices($product_id, xnt_tier_by_key($product_id, 'normalna-cena'))[1] ?? 0);

    $price = '<b>' . esc_html(xnt_plain_price($day)) . '</b>';
    if ($day_full > $day + 0.005) {
        $price .= '<del class="xnt-ov-del" aria-hidden="true">'
            . esc_html(xnt_plain_price($day_full)) . '</del>';
    }

    // The three meta phrases are the only copy in the block that carries markup.
    $inline = ['b' => [], 'del' => ['class' => [], 'aria-hidden' => []]];

    return '<div class="xnt xnt-checkout xnt-co-banner">'
        . '<section class="xnt-ev">'
        . '<div>'
        . '<div class="xnt-eyebrow">' . esc_html(xnt_checkout_text('eveyebrow', $language)) . '</div>'
        . '<h1 class="xnt-disp xnt-grad">' . esc_html(xnt_checkout_text('evtitle', $language)) . '</h1>'
        . '<div class="xnt-ev-sub">' . esc_html(xnt_checkout_text('evsub', $language)) . '</div>'
        . '<div class="xnt-ev-meta">'
        . '<span>' . wp_kses(xnt_fill(xnt_checkout_text('evday', $language), ['%1' => $price]), $inline) . '</span>'
        . '<span>' . wp_kses(xnt_checkout_text('evcheaper', $language), $inline) . '</span>'
        . '<span>' . wp_kses(xnt_checkout_text('evsame', $language), $inline) . '</span>'
        . '</div>'
        . '</div>'
        . xnt_render_eb_pill($language)
        . '</section>'
        . '</div>';
}

/**
 * The banner opens the checkout, above the form.
 *
 * Elementor's checkout widget renders WooCommerce's own form-checkout.php, so
 * woocommerce_before_checkout_form still fires inside it and puts this full
 * width above the two columns - which is where the design has it, and why it
 * is not part of the selection UI that has to sit inside the billing column.
 */
add_action('woocommerce_before_checkout_form', function () {
    if (!xnt_review_applies()) {
        return;
    }
    echo xnt_render_banner(xnt_current_language());
}, 5);
