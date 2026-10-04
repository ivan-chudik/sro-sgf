<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Alternative products of one event, picked with two cascading selects at checkout.
 *
 * The same event runs in several places (Bratislava, Košice, later Žilina) and each
 * place is its own product: own date, own capacity, own price. Without this the
 * visitor has to find the right product link, and the shop sells other events
 * alongside, so a plain product list is confusing.
 *
 * Products join a group by sharing the group key on the product. One city now holds
 * several products - several themes, each on its own date - so the picker asks in
 * two steps, city and then theme-with-date, and the first step keeps the second
 * list short enough to read. Switching swaps the cart item, because another
 * product means another date, capacity and price, so nothing of the old choice
 * carries over.
 */
class ProductGroup
{
    /** Group key. Products sharing it are alternatives of one event. */
    public const PRODUCT_GROUP = '_alttag_product_group';

    /** What this product is called inside the group, e.g. "Bratislava (11. 9. 2026)". */
    public const PRODUCT_OPTION = '_alttag_product_group_option';

    /** City the product runs in, the first of the two picks. */
    public const PRODUCT_CITY = '_alttag_product_city';

    /** What is played, e.g. "Vesmírny piatok". Half of the second pick, the date being the other. */
    public const EVENT_THEME = '_alttag_event_theme';

    private const ACTION = 'alttag_switch_group_product';

    /**
     * Where the visitor's own pick is remembered.
     *
     * Holding the product is not the same as having picked a place: a product link
     * or the shop is what put it in the cart. So the selects start with nothing
     * chosen and this key is what tells "the visitor picked this" apart from
     * "this is what the cart happens to hold". It also has to outlive the request,
     * because picking a date reloads the page.
     */
    private const SESSION_CHOICE = 'alttag_product_group_choice';

    /** The date select's name, also read back from the checkout POST. */
    private const FIELD = 'alttag_product_group';

    /**
     * The one selection that is not about the product.
     *
     * How many adults and children are coming is the same answer in every city,
     * so it is the only thing carried over a switch; everything else a selection
     * type stores is a date or a time of the product it was picked on.
     */
    private const KEPT_SELECTION = 'participant_types';

    public function registerHooks()
    {
        add_action('woocommerce_before_checkout_billing_form', [$this, 'renderSelect'], 7);
        add_action('woocommerce_checkout_process', [$this, 'requireChoice'], 5);
        add_action('woocommerce_add_to_cart', [$this, 'forgetChoice'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'productFields']);
        add_action('woocommerce_process_product_meta', [$this, 'saveProductFields']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'handleSwitch']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handleSwitch']);
        add_action('alttag_registrations_group_product_switched', [$this, 'forgetSelections']);
    }

    /** Group key of a product, empty when it is not grouped. */
    public static function groupOf($product_id): string
    {
        return (string) get_post_meta((int) $product_id, self::PRODUCT_GROUP, true);
    }

    /** How a product presents itself in the select. */
    public static function optionLabel($product_id): string
    {
        $label = (string) get_post_meta((int) $product_id, self::PRODUCT_OPTION, true);
        if ($label !== '') {
            return $label;
        }

        // No explicit label: the venue is what tells the options apart.
        $venue = Settings::getValue('general.venue', (int) $product_id);

        return $venue !== '' ? $venue : (string) get_post_field('post_title', (int) $product_id);
    }

    /**
     * The option label split into the place and the detail it carries in brackets.
     *
     * A label is "Bratislava, FMFI UK (11. 9. 2026)" - place in front, date in
     * brackets - and cityOf() reads the place out of it for products that have no
     * city of their own. The bracket is only the convention the product field
     * asks for, so a label without one is all place and no date.
     *
     * @return array{place: string, meta: string}
     */
    private static function optionParts($product_id): array
    {
        $label = self::optionLabel($product_id);

        if (preg_match('/^(.+?)\s*\((.+)\)\s*$/u', $label, $match)) {
            return ['place' => trim($match[1]), 'meta' => trim($match[2])];
        }

        return ['place' => $label, 'meta' => ''];
    }

    /**
     * The city a product runs in, which is what the first select offers.
     *
     * The meta field is the answer when it is filled in. It is not on the older
     * products, whose option label is "Bratislava, FMFI UK (11. 9. 2026)" - city,
     * venue, date - so the segment before the comma is the city and they keep
     * working with nothing to edit in the database.
     */
    public static function cityOf($product_id): string
    {
        $city = trim((string) get_post_meta((int) $product_id, self::PRODUCT_CITY, true));
        if ($city !== '') {
            return $city;
        }

        $place = self::optionParts($product_id)['place'];
        $segments = explode(',', $place);

        return trim($segments[0]);
    }

    /**
     * How one date reads in the second select: what is played, and when.
     *
     * The city is already answered by the select above, so the option label
     * ("Bratislava, FMFI UK (11. 9. 2026)") cannot be used as it stands - it
     * repeats the city on every row. The theme meta and the date out of the
     * brackets are the two halves that are still news, and a theme with several
     * dates simply gets one row per date.
     *
     * Products from before the themes existed have no theme meta, and there the
     * date alone is what tells them apart, which is the one-theme-per-city era
     * read through the same two selects and nothing to edit in the database.
     */
    public static function eventLabel($product_id): string
    {
        $theme = trim((string) get_post_meta((int) $product_id, self::EVENT_THEME, true));
        $date = self::optionParts($product_id)['meta'];

        if ($theme !== '' && $date !== '') {
            return self::joinParts($theme, $date);
        }

        if ($theme !== '') {
            return $theme;
        }

        // No date in brackets either: the whole option label is all there is.
        return $date !== '' ? $date : self::optionLabel($product_id);
    }

    /**
     * The group as the two selects read it: cities and, under each, its events.
     *
     * Built once and used twice - the renderer prints the first select and the
     * branch the visitor already picked out of it, the script fills the rest from
     * the same array, so the options cannot drift apart. Order is the order
     * siblings() returns, i.e. menu_order then title.
     *
     * A city nobody can book any more is kept in its list and only says so:
     * dropping it would read as a place we never went to. The label already
     * carries the sold-out suffix, so neither side has to assemble it.
     *
     * @return array<int, array{
     *     city: string, label: string, available: bool, events: array<int, array{
     *         id: int, label: string, available: bool}>}>
     */
    private static function groupTree(int $current): array
    {
        $grouped = [];

        foreach (self::siblings($current) as $product_id) {
            $city = self::cityOf($product_id);
            if ($city === '') {
                continue;
            }

            $available = self::isAvailable($product_id);
            $label = self::eventLabel($product_id);

            $grouped[$city][] = [
                'id' => (int) $product_id,
                'label' => $available ? $label : self::soldOutLabel($label),
                'available' => $available,
            ];
        }

        $tree = [];

        foreach ($grouped as $city => $events) {
            // A city is bookable while any of its events is.
            $city_available = false;
            foreach ($events as $event) {
                $city_available = $city_available || $event['available'];
            }

            // (string): a city that reads as a number came back from the array
            // key as an int.
            $city = (string) $city;
            $tree[] = [
                'city' => $city,
                'label' => $city_available ? $city : self::soldOutLabel($city),
                'available' => $city_available,
                'events' => $events,
            ];
        }

        return $tree;
    }

    /** An option label with the sold-out state spelled out, for a disabled option. */
    private static function soldOutLabel(string $label): string
    {
        return self::joinParts($label, __('Sold out', 'alttag-registrations'));
    }

    /** The two halves of an option label, joined the one way every option joins them. */
    private static function joinParts(string $first, string $second): string
    {
        /* translators: 1: first half of an option label, e.g. the theme; 2: the second, e.g. the date or "Sold out". */
        return sprintf(__('%1$s - %2$s', 'alttag-registrations'), $first, $second);
    }

    /**
     * Products in the same group, including the one asked about.
     *
     * @return int[]
     */
    public static function siblings($product_id): array
    {
        $group = self::groupOf($product_id);
        if ($group === '') {
            return [];
        }

        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'meta_query' => [
                ['key' => self::PRODUCT_GROUP, 'value' => $group],
            ],
        ]);

        return array_map('intval', (array) $ids);
    }

    /** The grouped product currently in the cart, if any. */
    private static function cartProductId(): int
    {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = (int) ($cart_item['product_id'] ?? 0);
            if ($product_id && self::groupOf($product_id) !== '') {
                return $product_id;
            }
        }

        return 0;
    }

    /**
     * The place the visitor picked themselves, 0 until they answer the selects.
     *
     * A remembered pick counts only while it is what the cart holds. Anything else
     * - the item removed, another place added from the shop meanwhile - is the cart
     * being changed behind the picker's back, which is not a choice, so the
     * selects go back to nothing chosen.
     */
    private static function chosenProductId(): int
    {
        if (!function_exists('WC') || !WC()->session) {
            return 0;
        }

        $chosen = (int) WC()->session->get(self::SESSION_CHOICE, 0);

        return $chosen && $chosen === self::cartProductId() ? $chosen : 0;
    }

    private static function rememberChoice(int $product_id)
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_CHOICE, $product_id);
        }
    }

    /**
     * Adding a grouped product to the cart un-picks the selects.
     *
     * Otherwise the pick would survive the registration it was made for: order
     * placed, cart empty, session still alive, and the next registration would
     * arrive at the checkout with a place already selected. handleSwitch() writes
     * the pick after its own add_to_cart(), so its switch is not caught by this.
     *
     * @param string $cart_item_key
     * @param int    $product_id
     */
    public function forgetChoice($cart_item_key, $product_id)
    {
        if (self::groupOf($product_id) === '') {
            return;
        }

        self::rememberChoice(0);
    }

    /**
     * The cart's grouped product when the picker has something to offer, else 0.
     *
     * Shared by the renderer and the enqueue guard so the assets load exactly
     * on the pages that print the selects.
     */
    private static function shouldRender(): int
    {
        $current = self::cartProductId();
        if (!$current) {
            return 0;
        }

        return count(self::siblings($current)) < 2 ? 0 : $current;
    }

    /** The two selects of the event picker, on the checkout page only. */
    public function enqueueAssets()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $current = self::shouldRender();
        if (!$current) {
            return;
        }

        $css_path = ALTTAG_REGISTRATIONS_PATH . 'assets/css/checkout-product-group.css';
        wp_enqueue_style(
            'alttag-checkout-product-group',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/checkout-product-group.css',
            [],
            file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0'
        );

        $js_path = ALTTAG_REGISTRATIONS_PATH . 'assets/js/checkout-product-group.js';
        wp_enqueue_script(
            'alttag-checkout-product-group',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/checkout-product-group.js',
            // jQuery: the submit guard hangs off WooCommerce's checkout_place_order
            // event, which is a jQuery event with no DOM equivalent.
            ['jquery'],
            file_exists($js_path) ? (string) filemtime($js_path) : '1.0.0',
            true
        );

        wp_localize_script('alttag-checkout-product-group', 'alttagProductGroup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            // Said only when the request itself fails; a refused switch answers with
            // its own reason ("That date is sold out." and friends).
            'failedMessage' => __('The event could not be switched, please try again.', 'alttag-registrations'),
            // The whole group as cities -> events, so answering the first select
            // can fill the second without a round trip. Labels come from here
            // rather than being assembled in JS, so PHP stays the one place that
            // decides how an option reads.
            'cities' => self::groupTree($current),
            // The placeholder the script has to re-create when it refills the
            // second select, in the words the renderer printed it with.
            'eventPlaceholder' => __('Select theme and date', 'alttag-registrations'),
        ]);
    }

    public function renderSelect()
    {
        $current = self::shouldRender();
        if (!$current) {
            return;
        }

        $tree = self::groupTree($current);

        // Not $current: the cart holds a product, but the visitor has not picked
        // one until they say so, and only a pick may show as selected.
        $chosen = self::chosenProductId();
        $chosen_city = $chosen ? self::cityOf($chosen) : '';

        $city_label = Settings::getValue('general.product_group_label', $current);
        if ($city_label === '') {
            $city_label = __('Location', 'alttag-registrations');
        }

        // The one branch of the tree a remembered pick reaches through, which is
        // all the renderer has to print - the rest of the tree arrives with the
        // script and is filled in as the visitor answers, see enqueueAssets().
        $events = [];
        foreach ($tree as $city) {
            if ($city['city'] === $chosen_city) {
                $events = $city['events'];
            }
        }

        // validate-required is WooCommerce's "this has to be filled in" marker:
        // express-checkout-guard.js walks those wrappers and reads the one field
        // inside, so the wallet buttons stay blocked too. One row per select,
        // because a wrapper holding several would only ever be asked about the
        // first.
        ?>
        <div class="alttag-product-group" id="alttag-product-group"
             data-nonce="<?php echo esc_attr(wp_create_nonce(self::ACTION)); ?>">
            <p class="alttag-product-group__hint" id="alttag-product-group-hint">
                <?php esc_html_e('Pick the location, then the theme and date. Date, capacity and price differ per event.', 'alttag-registrations'); ?>
            </p>
            <p class="form-row form-row-wide validate-required alttag-product-group__row">
                <label for="alttag-product-group-city">
                    <?php echo esc_html($city_label); ?>
                    <abbr class="required" title="required">*</abbr>
                </label>
                <select name="alttag_product_group_city" id="alttag-product-group-city"
                        class="select alttag-product-group__select"
                        aria-describedby="alttag-product-group-hint">
                    <option value=""><?php echo esc_html__('Select location', 'alttag-registrations'); ?></option>
                    <?php foreach ($tree as $city) : ?>
                        <option value="<?php echo esc_attr($city['city']); ?>"
                                <?php selected($city['city'], $chosen_city); ?>
                                <?php disabled(!$city['available'], true); ?>>
                            <?php echo esc_html($city['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <?php
            // The second select is empty and disabled until the first has an
            // answer: its options only mean something inside that answer, and the
            // full list of them - every theme of every city - is what the cascade
            // exists to avoid showing at once.
            ?>
            <p class="form-row form-row-wide validate-required alttag-product-group__row">
                <label for="alttag-product-group-event">
                    <?php echo esc_html__('Theme and date', 'alttag-registrations'); ?>
                    <abbr class="required" title="required">*</abbr>
                </label>
                <select name="<?php echo esc_attr(self::FIELD); ?>" id="alttag-product-group-event"
                        class="select alttag-product-group__select"
                        aria-describedby="alttag-product-group-hint"
                        <?php disabled($chosen_city === '', true); ?>>
                    <option value=""><?php echo esc_html__('Select theme and date', 'alttag-registrations'); ?></option>
                    <?php foreach ($events as $event) : ?>
                        <option value="<?php echo esc_attr($event['id']); ?>"
                                <?php selected($event['id'], $chosen); ?>
                                <?php disabled(!$event['available'], true); ?>>
                            <?php echo esc_html($event['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <?php
            // Rendered with its text in place and only revealed by the submit
            // guard, so the string stays in PHP and role="alert" announces it
            // the moment it appears. Deliberately not in aria-describedby:
            // browsers disagree about announcing a hidden description.
            ?>
            <p class="alttag-product-group__error" id="alttag-product-group-error" role="alert" hidden>
                <?php echo esc_html(self::missingChoiceMessage()); ?>
            </p>
        </div>
        <?php
    }

    /** Said by the submit guard and by the checkout validation, so it is one string. */
    private static function missingChoiceMessage(): string
    {
        return __('Please pick the location, theme and date you want to attend.', 'alttag-registrations');
    }

    /**
     * No order before the visitor has picked a place.
     *
     * The selects start empty on purpose, so without this the checkout would go
     * through on whatever product happened to be in the cart - the visitor never
     * chose it, and the places differ in date, capacity and price.
     */
    public function requireChoice()
    {
        $current = self::shouldRender();
        if (!$current) {
            return;
        }

        if (self::chosenProductId()) {
            return;
        }

        // The posted date counts as a pick as well. Nothing is preselected, so a
        // value can only come from a pick, and it is only honoured while it is
        // what the cart holds - the switch runs over AJAX and is what changed the
        // cart, so a value pointing elsewhere means the switch never happened.
        $posted = isset($_POST[self::FIELD]) ? (int) $_POST[self::FIELD] : 0;
        if ($posted === $current) {
            self::rememberChoice($current);
            return;
        }

        wc_add_notice(self::missingChoiceMessage(), 'error');
    }

    /** Whether a sibling can still be bought, i.e. is offerable as an option. */
    private static function isAvailable($product_id): bool
    {
        $product = function_exists('wc_get_product') ? wc_get_product((int) $product_id) : null;

        return $product && $product->is_purchasable() && $product->is_in_stock();
    }

    /** Swap the cart item for another product of the same group. */
    public function handleSwitch()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::ACTION)) {
            wp_send_json_error(['message' => __('Expired request, please reload the page.', 'alttag-registrations')], 400);
        }

        $target = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $current = self::cartProductId();

        if (!$target || !$current) {
            wp_send_json_error(['message' => __('Nothing to switch.', 'alttag-registrations')], 400);
        }

        // Only inside the same group, so this cannot be used to put an arbitrary
        // product into somebody's cart.
        if ($target !== $current && !in_array($target, self::siblings($current), true)) {
            wp_send_json_error(['message' => __('That product is not part of this event.', 'alttag-registrations')], 400);
        }

        if ($target === $current) {
            // Picking the place the cart already holds changes nothing in the cart,
            // but it is still the pick the checkout is waiting for.
            self::rememberChoice($target);
            wp_send_json_success(['changed' => false]);
        }

        if (!self::isAvailable($target)) {
            wp_send_json_error(['message' => __('That date is sold out.', 'alttag-registrations')], 409);
        }

        // Add first, remove second: a sold-out or otherwise rejected target used
        // to leave the visitor with an empty cart, because the old item was gone
        // before the new one was tried.
        $added = WC()->cart->add_to_cart($target, 1);
        if (!$added) {
            // The failed add left an error notice in the session; it would pop up
            // on some unrelated page later, so it goes back in the response.
            $message = self::takeFirstErrorNotice();
            wp_send_json_error(
                ['message' => $message !== '' ? $message : __('The product could not be added.', 'alttag-registrations')],
                500
            );
        }

        foreach (WC()->cart->get_cart() as $key => $cart_item) {
            if ($key !== $added && (int) ($cart_item['product_id'] ?? 0) === $current) {
                WC()->cart->remove_cart_item($key);
            }
        }

        // After add_to_cart(), whose own hook clears the pick again.
        self::rememberChoice($target);

        do_action('alttag_registrations_group_product_switched', $target, $current);

        // The new product has its own price, sessions and capacity, so the
        // totals the page still shows are the old product's.
        WC()->cart->calculate_totals();

        // Everything the page has to be told apart from the cart itself, in one
        // answer: the switch used to reload instead, which is the same work plus
        // a whole page. Order review is built the way WC_AJAX::update_order_review
        // builds it, so the fragment is the one the checkout scripts expect.
        ob_start();
        woocommerce_order_review();
        $order_review = (string) ob_get_clean();

        wp_send_json_success([
            'changed' => true,
            'fragments' => [
                '#alttag-selection-ui' => '<div id="alttag-selection-ui">'
                    . Selection\SelectionManager::renderCheckoutUIHtml() . '</div>',
                '.woocommerce-checkout-review-order-table' => $order_review,
            ],
            // The tree is rebuilt because availability moves with the cart: the
            // product just left may now be bookable again, the one taken may not.
            'cities' => self::groupTree($target),
            'selectionConfigs' => Selection\SelectionManager::selectionConfigsArray(),
            'cartHash' => WC()->cart->get_cart_hash(),
        ]);
    }

    /**
     * Drop the selections that belonged to the product being left.
     *
     * A date and a wave live in the WooCommerce session, not on the cart item,
     * so swapping the item leaves them standing: the 18:05 picked in Bratislava
     * was still in the session at Kosice, was rendered back as the pick and
     * would have been written to the order and the participant. The times are
     * not even the same list - each product has its own waves.
     *
     * Hooked on the switch, so it runs before the fragments are rendered and the
     * answer comes back with a picker that has nothing chosen, and it covers the
     * reload path for the same reason - both are the same request.
     */
    public function forgetSelections()
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        // A product-scoped type has one key per cart product, so dropping the
        // bare key alone would leave the old product's selection standing.
        $product_ids = \Alttag\Registrations\get_cart_product_ids();

        foreach (Selection\SelectionManager::getTypes() as $type) {
            if ($type->getId() === self::KEPT_SELECTION) {
                continue;
            }

            $scopes = $type->isScopedByProduct() ? $product_ids : [];
            $scopes[] = 0;

            foreach ($scopes as $product_id) {
                WC()->session->__unset($type->getSessionKey($product_id));
                WC()->session->__unset($type->getDataSessionKey($product_id));
            }
        }
    }

    /** First queued WooCommerce error, removed from the session. */
    private static function takeFirstErrorNotice(): string
    {
        if (!function_exists('wc_get_notices')) {
            return '';
        }

        $errors = wc_get_notices('error');
        if (!$errors) {
            return '';
        }

        // Only the errors go away; success/info notices belong to other requests.
        $all = wc_get_notices();
        unset($all['error']);
        wc_set_notices($all);

        $first = $errors[0]['notice'] ?? '';

        return trim(wp_strip_all_tags((string) $first));
    }

    // ------------------------------------------------------------ product admin

    public function productFields()
    {
        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id' => self::PRODUCT_GROUP,
            'label' => __('Event group', 'alttag-registrations'),
            'desc_tip' => true,
            'description' => __(
                'Products sharing this key are alternatives of one event (e.g. one city each) and the checkout offers a select between them.',
                'alttag-registrations'
            ),
        ]);

        woocommerce_wp_text_input([
            'id' => self::PRODUCT_OPTION,
            'label' => __('Name inside the group', 'alttag-registrations'),
            'desc_tip' => true,
            'description' => __(
                'What this product is called in that select, e.g. "Bratislava (11. 9. 2026)". Falls back to the venue.',
                'alttag-registrations'
            ),
        ]);

        woocommerce_wp_text_input([
            'id' => self::PRODUCT_CITY,
            'label' => __('Location', 'alttag-registrations'),
            'desc_tip' => true,
            'description' => __(
                'The location the checkout offers first, e.g. "Bratislava FMFI UK". Products of one location are offered together. Falls back to what stands before the comma in the name inside the group.',
                'alttag-registrations'
            ),
        ]);

        woocommerce_wp_text_input([
            'id' => self::EVENT_THEME,
            'label' => __('Theme', 'alttag-registrations'),
            'desc_tip' => true,
            'description' => __(
                'What is played, e.g. "Vesmírny piatok". Offered with the date once the city is picked. Falls back to the date alone.',
                'alttag-registrations'
            ),
        ]);

        echo '</div>';
    }

    public function saveProductFields($post_id)
    {
        foreach ([self::PRODUCT_GROUP, self::PRODUCT_OPTION, self::PRODUCT_CITY, self::EVENT_THEME] as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($_POST[$key]));
            if ($key === self::PRODUCT_GROUP) {
                $value = sanitize_key($value);
            }
            if ($value === '') {
                delete_post_meta($post_id, $key);
                continue;
            }
            update_post_meta($post_id, $key, $value);
        }
    }
}
