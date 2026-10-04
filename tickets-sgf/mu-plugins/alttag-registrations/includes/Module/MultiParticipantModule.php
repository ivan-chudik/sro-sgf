<?php

namespace Alttag\Registrations\Module;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MultiParticipantModule — one order, many attendees.
 *
 * When the ParticipantType counter on checkout is > 1, N-1 additional
 * "attendee" field-sets appear after the buyer's identity fields (first_name,
 * last_name, e-mail, job_title per attendee). On order completion each becomes
 * its own participant record with its own ticket + email, linked back to the
 * buyer via `registered_by_participant_id` / `registered_by_email` meta.
 *
 * The buyer is always the 1st participant (created by the core plugin from
 * billing_* fields). Extra attendees inherit company info from the buyer's
 * order (billing_company / VAT / etc.) and share the product + session +
 * a variable-symbol prefix.
 *
 * Depends on ParticipantTypeModule (for the seat counter). Renders a JS-only
 * container to sidestep Elementor Pro's checkout widget echoing the billing
 * form more than once per request (which would multiply the container).
 */
class MultiParticipantModule extends AbstractModule
{
    public function getId(): string
    {
        return 'multi_participant';
    }

    public function getName(): string
    {
        return __('Multi-participant Checkout', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __(
            'Collect first name, last name, e-mail and job title per attendee when Number of Seats > 1. Each attendee becomes a separate participant with their own ticket and confirmation e-mail.',
            'alttag-registrations'
        );
    }

    public function getSettingsTab(): ?string
    {
        return 'modules';
    }

    public function hasProductToggle(): bool
    {
        return true;
    }

    public function getDependencies(): array
    {
        return ['participant_types'];
    }

    public function registerHooks(): void
    {
        // Assets on checkout
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        // Validation + persistence
        add_action('woocommerce_after_checkout_validation', [$this, 'validateFields'], 20, 2);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveToOrder'], 30);

        // Auto-registration on order confirmation. We MUST run after the
        // buyer's participant is created (WooCommerceManager, on
        // woocommerce_order_status_changed priority 10) — otherwise we'd:
        //   (a) fail to link extras to the buyer (buyer_participant_id = 0),
        //   (b) create the FIRST participant for this order with the extra's
        //       post_title, causing the buyer's later createOrUpdateParticipant
        //       to match the extra by order_id and overwrite its meta.
        // WC fires `_status_completed` BEFORE `_status_changed`, so hooking
        // there produces the wrong order. Hook onto `_status_changed`
        // instead, at priority 20 (after WooCommerceManager's 10), and filter
        // to processing/completed so extras aren't materialised on cancel /
        // refund / pending transitions.
        add_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 20, 3);

        // Register meta fields for admin / exports / emails
        add_filter('alttag_registrations_meta_fields', [$this, 'registerMetaFields']);

        // Enrich SuperFaktura invoice comment with the full attendee list
        // so the customer sees every ticket they're paying for on the invoice.
        add_filter('alttag_registrations_invoice_data', [$this, 'appendAttendeesToInvoiceComment'], 10, 3);

        // Strip the aggregated participant-types map from $order_data on any
        // order that used multi-participant checkout. Without this, every
        // subsequent status-changed fire re-runs WooCommerceManager's
        // updateParticipant → metaFields loop → re-copies the ORDER's
        // 'selected_participant_types_data' onto the buyer's participant,
        // resurrecting the "Number of seats 0/2" per-type check-in table on
        // the admin list even after we cleared it during registerExtraParticipants.
        // Each extra is its own participant now — the aggregate belongs to
        // nobody, so remove it from the shared order_data pipeline.
        add_filter('alttag_registrations_order_data', [$this, 'stripAggregatedTypesFromOrderData'], 20, 2);

        // Surface "registered by e-mail" in the participants list column set
        // so an admin can see at a glance which buyer registered each extra
        // (extras carry `registered_by_email`; buyers have it empty).
        add_filter('alttag_registrations_participant_columns', [$this, 'addRegisteredByColumn']);
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderRegisteredByColumn'], 10, 3);
    }

    /**
     * Insert a "Registered by" column right before the Actions column.
     */
    public function addRegisteredByColumn(array $columns): array
    {
        $actions = $columns['actions'] ?? null;
        if ($actions !== null) {
            unset($columns['actions']);
        }
        $columns['registered_by_email'] = __('Registered by', 'alttag-registrations');
        if ($actions !== null) {
            $columns['actions'] = $actions;
        }
        return $columns;
    }

    /**
     * Render the "Registered by" column. Returning `null` lets the default
     * column handler take over for other columns.
     */
    public function renderRegisteredByColumn($content, $column, $post_id)
    {
        if ($column !== 'registered_by_email') {
            return $content;
        }
        $email = get_post_meta($post_id, 'registered_by_email', true);
        $name  = get_post_meta($post_id, 'registered_by_name', true);
        if (empty($email) && empty($name)) {
            return '—';
        }
        $parts = [];
        if (!empty($name)) {
            $parts[] = esc_html($name);
        }
        if (!empty($email)) {
            $parts[] = '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        }
        return implode('<br>', $parts);
    }

    /**
     * Prevent the buyer's participant from being tagged with the ORDER-level
     * aggregate participant-types map on subsequent updates.
     *
     * Root cause of the "Number of seats 0/2" resurfacing on the admin
     * participants list even after `registerExtraParticipants` scrubs the
     * buyer: `WooCommerceManager::getOrderData()` flattens the order item's
     * `selected_participant_types_data` into `$order_data`, and every later
     * `handleOrderStatusChange` re-runs `updateParticipant`'s metaFields
     * loop with that same $order_data — overwriting our earlier delete.
     *
     * Solution: strip those two keys from the shared pipeline as soon as
     * the order has extras. Each extra is its own participant now, so the
     * aggregate has no owner and shouldn't ride along the buyer's meta.
     */
    public function stripAggregatedTypesFromOrderData($order_data, $order): array
    {
        if (!is_array($order_data) || !($order instanceof \WC_Order)) {
            return is_array($order_data) ? $order_data : [];
        }
        $extras = get_post_meta($order->get_id(), '_alttag_extra_participants', true);
        if (!is_array($extras) || empty($extras)) {
            return $order_data;
        }
        unset(
            $order_data['selected_participant_types'],
            $order_data['selected_participant_types_data']
        );
        return $order_data;
    }

    /**
     * Replace the single "Participant: Buyer" line WooCommerceManager writes
     * into the invoice comment with a unified "Participant N: Name (email)"
     * roster covering the buyer + every extra attendee, so the invoice
     * matches what the customer sees on the thank-you page. Only kicks in
     * when extras exist — solo orders keep the original one-liner.
     */
    public function appendAttendeesToInvoiceComment($invoice_data, $order, $type): array
    {
        if (!is_array($invoice_data) || !($order instanceof \WC_Order)) {
            return $invoice_data;
        }
        $extras = get_post_meta($order->get_id(), '_alttag_extra_participants', true);
        if (!is_array($extras) || empty($extras)) {
            return $invoice_data;
        }

        $buyer_name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $buyer_email = $order->get_billing_email();

        // Buyer first, extras after. Numbering is 1-based across the entire
        // roster so admin + customer can talk about "Participant 2" without
        // ambiguity between buyer-vs-attendee terminology.
        $roster = [
            [
                'name'  => $buyer_name,
                'email' => $buyer_email,
            ],
        ];
        foreach ($extras as $attendee) {
            $first = trim((string) ($attendee['first_name'] ?? ''));
            $last  = trim((string) ($attendee['last_name']  ?? ''));
            $email = trim((string) ($attendee['email']      ?? ''));
            $name  = trim($first . ' ' . $last);
            if ($name === '' && $email === '') {
                continue;
            }
            $roster[] = [
                'name'  => $name !== '' ? $name : $email,
                'email' => $email,
            ];
        }

        $lines = [];
        foreach ($roster as $i => $p) {
            $label = sprintf(
                /* translators: 1: 1-based participant number, 2: full name, 3: e-mail */
                __('Participant %1$d: %2$s (%3$s)', 'alttag-registrations'),
                $i + 1,
                $p['name'],
                $p['email']
            );
            $lines[] = $label;
        }

        // Strip WooCommerceManager's original "Participant: <buyer>" line so
        // it doesn't appear alongside "Participant 1: <buyer> (email)".
        // Matches the localised prefix from addInvoiceData() (line starts
        // with the "Participant:" label and runs to the next newline).
        $comment = (string) ($invoice_data['comment'] ?? '');
        if ($comment !== '') {
            $prefix = __('Participant:', 'alttag-registrations');
            $comment = preg_replace(
                '/(?:^|\r?\n)\s*' . preg_quote($prefix, '/') . '.*(?=\r?\n|$)/u',
                '',
                $comment,
                1
            );
            $comment = trim($comment, "\r\n");
        }

        $roster_block = implode("\r\n", $lines);
        $invoice_data['comment'] = $comment !== ''
            ? $roster_block . "\r\n" . $comment
            : $roster_block;

        return $invoice_data;
    }

    /**
     * Gate `registerExtraParticipants` to positive status transitions only.
     */
    public function onOrderStatusChanged($order_id, $old_status, $new_status): void
    {
        if (!in_array($new_status, ['processing', 'completed'], true)) {
            return;
        }
        $this->registerExtraParticipants($order_id);
    }

    // =========================================================================
    // Assets
    // =========================================================================

    public function enqueueAssets(): void
    {
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }

        $base_url = defined('ALTTAG_REGISTRATIONS_URL')
            ? ALTTAG_REGISTRATIONS_URL
            : plugin_dir_url(dirname(__DIR__));
        $base_path = defined('ALTTAG_REGISTRATIONS_PATH')
            ? ALTTAG_REGISTRATIONS_PATH
            : dirname(__DIR__, 2);

        $js_file  = $base_path . '/assets/js/multi-participant.js';
        $css_file = $base_path . '/assets/css/multi-participant.css';

        if (file_exists($js_file)) {
            wp_enqueue_script(
                'alttag-multi-participant',
                $base_url . 'assets/js/multi-participant.js',
                ['jquery'],
                filemtime($js_file),
                true
            );
            wp_localize_script('alttag-multi-participant', 'alttagMultiParticipant', [
                'i18n' => [
                    'attendee_heading'      => __('Attendee %d', 'alttag-registrations'),
                    'first_name'            => __('First name', 'alttag-registrations'),
                    'last_name'             => __('Last name', 'alttag-registrations'),
                    'email'                 => __('E-mail', 'alttag-registrations'),
                    'job_title'             => __('Job title', 'alttag-registrations'),
                    'job_title_placeholder' => __('e.g. DevOps Engineer', 'alttag-registrations'),
                    'info'                  => __(
                        'Additional attendees will receive their own ticket by email. Company info from the buyer is copied automatically.',
                        'alttag-registrations'
                    ),
                ],
            ]);
        }

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'alttag-multi-participant',
                $base_url . 'assets/css/multi-participant.css',
                [],
                filemtime($css_file)
            );
        }
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function validateFields($data, $errors): void
    {
        $extras            = $this->extractFromPost();
        $total_seats       = $this->computeTotalSeatsFromPost();
        $expected_extras   = max(0, $total_seats - 1);
        $extras_count      = count($extras);

        // Bail early on solo orders and when the module isn't in play. The
        // "counter says 2 but only 1 attendee filled" state is the failure
        // mode a returning buyer hits on refresh: their previous counter
        // selection persists in session, they never notice the empty extra
        // attendee field, and submitting silently creates only the buyer.
        // Raise a clear error so the buyer must complete the roster.
        if ($expected_extras > 0 && $extras_count < $expected_extras) {
            $missing = $expected_extras - $extras_count;
            $errors->add(
                'extra_participants_incomplete',
                sprintf(
                    /* translators: 1: number of missing attendee entries, 2: total expected extras */
                    _n(
                        'Please fill in the additional attendee field (%1$d of %2$d missing).',
                        'Please fill in all additional attendee fields (%1$d of %2$d missing).',
                        $missing,
                        'alttag-registrations'
                    ),
                    $missing,
                    $expected_extras
                )
            );
            // Fall through so any per-field errors below still surface.
        }

        if (empty($extras)) {
            return;
        }

        $buyer_email = strtolower(trim($data['billing_email'] ?? ''));
        $seen_emails = $buyer_email !== '' ? [$buyer_email] : [];

        // Cross-check extras against already-registered participants in the DB
        // for the same product(s) — same rule CheckoutManager applies to the
        // buyer. Gated by the same filter so an admin can disable it site-wide.
        $check_db_duplicates = apply_filters('alttag_registrations_enable_multi_day_duplicate_validation', true);
        $duplicate_product_ids = [];
        if ($check_db_duplicates) {
            foreach (\Alttag\Registrations\get_cart_product_ids() as $product_id) {
                if (apply_filters('alttag_registrations_skip_email_duplicate_check', false, $product_id)) {
                    continue;
                }
                $duplicate_product_ids[] = (int) $product_id;
            }
        }

        foreach ($extras as $idx => $attendee) {
            $n = $idx + 2; // 1-indexed with buyer as #1
            foreach (['first_name', 'last_name', 'email', 'job_title'] as $key) {
                $val = trim((string) ($attendee[$key] ?? ''));
                if ($val === '') {
                    $errors->add(
                        'extra_participant_missing_' . $idx . '_' . $key,
                        sprintf(
                            /* translators: 1: attendee number, 2: field label */
                            __('Attendee %1$d: %2$s is required.', 'alttag-registrations'),
                            $n,
                            $this->fieldLabel($key)
                        )
                    );
                }
            }
            $email = strtolower(trim($attendee['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            if (!is_email($email)) {
                $errors->add(
                    'extra_participant_bad_email_' . $idx,
                    sprintf(
                        __('Attendee %d: e-mail address is not valid.', 'alttag-registrations'),
                        $n
                    )
                );
                continue;
            }
            if (in_array($email, $seen_emails, true)) {
                $errors->add(
                    'extra_participant_duplicate_email_' . $idx,
                    sprintf(
                        __(
                            'Attendee %d: e-mail must be different from the buyer and other attendees.',
                            'alttag-registrations'
                        ),
                        $n
                    )
                );
                continue;
            }
            $seen_emails[] = $email;

            // DB duplicate check — is this e-mail already registered for a
            // product in the current cart?
            if (!empty($duplicate_product_ids) && $this->emailAlreadyRegisteredForProducts($email, $duplicate_product_ids)) {
                $errors->add(
                    'extra_participant_db_duplicate_email_' . $idx,
                    sprintf(
                        /* translators: 1: attendee number, 2: e-mail */
                        __('Attendee %1$d: e-mail %2$s is already registered for this product. Use a different e-mail address.', 'alttag-registrations'),
                        $n,
                        $email
                    )
                );
            }
        }
    }

    /**
     * Return true if any active participant with `email` = $email exists for
     * any of the given product IDs (mirrors CheckoutManager's buyer-side
     * duplicate check, minus the day-overlap logic — extras don't select
     * their own days, they inherit the buyer's).
     */
    private function emailAlreadyRegisteredForProducts(string $email, array $product_ids): bool
    {
        if ($email === '' || empty($product_ids)) {
            return false;
        }
        $existing = get_posts([
            'post_type'  => 'participant',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => 'email',      'value' => $email, 'compare' => '='],
                ['key' => 'product_id', 'value' => array_map('strval', $product_ids), 'compare' => 'IN'],
            ],
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        if (empty($existing)) {
            return false;
        }
        foreach ($existing as $participant_id) {
            $state = \Alttag\Registrations\ParticipantState::get($participant_id);
            if (!$state) {
                continue;
            }
            $status = strtolower((string) $state->getMeta('registration_status'));
            // Cancelled / archived registrations don't count as taking the seat.
            if (in_array($status, ['cancelled', 'canceled', 'refunded'], true)) {
                continue;
            }
            return true;
        }
        return false;
    }

    // =========================================================================
    // Persist to order
    // =========================================================================

    public function saveToOrder($order_id): void
    {
        $extras = $this->extractFromPost();
        if (empty($extras)) {
            delete_post_meta($order_id, '_alttag_extra_participants');
            return;
        }
        $clean = [];
        foreach ($extras as $attendee) {
            $clean[] = [
                'first_name' => sanitize_text_field($attendee['first_name'] ?? ''),
                'last_name'  => sanitize_text_field($attendee['last_name']  ?? ''),
                'email'      => sanitize_email($attendee['email']            ?? ''),
                'job_title'  => sanitize_text_field($attendee['job_title']  ?? ''),
            ];
        }
        update_post_meta($order_id, '_alttag_extra_participants', $clean);
    }

    // =========================================================================
    // Create extra participants on order-complete
    // =========================================================================

    public function registerExtraParticipants($order_id): void
    {
        if (get_post_meta($order_id, '_alttag_extra_participants_registered', true)) {
            return; // idempotency guard
        }
        $extras = get_post_meta($order_id, '_alttag_extra_participants', true);
        if (!is_array($extras) || empty($extras)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $core       = \Alttag\Registrations\Core::getInstance();
        $ctx        = \Alttag\Registrations\ctx()->withOrder($order);
        $product_id = $ctx->productId();

        // Ensure the buyer's participant exists BEFORE we create any extras.
        // Under real Stripe checkout we've observed WooCommerceManager's
        // handleOrderStatusChange sometimes running AFTER our extras hook
        // (rapid pending → completed transitions capture-in-flight, plus
        // theme-side races). If extras materialise first, the buyer's later
        // createOrUpdateParticipant matches the first extra by order_id and
        // overwrites its meta — leaving a single post with the extra's title
        // + the buyer's data, and no separate extras record.
        //
        // Driving buyer creation from here makes the outcome independent of
        // hook priority: we own the ordering explicitly, and the buyer is
        // always the first (lowest-ID) participant for the order.
        $buyer_participant_id = 0;
        $buyer_post = $core->participantManager->getParticipantByOrderId($order_id);
        if (isset($buyer_post->ID)) {
            $buyer_participant_id = (int) $buyer_post->ID;
        } else {
            // Buyer doesn't exist yet — create via the same flow that
            // WooCommerceManager::handleOrderStatusChange would use.
            $buyer_order_data = $core->wooCommerceManager->getOrderData($order);
            $created = $core->participantManager->createParticipant($buyer_order_data);
            if ($created) {
                $buyer_participant_id = (int) $created;
            }
        }

        // Under multi-participant flow, the buyer is now a single-seat
        // attendee too — their own participant carries just themselves.
        // Strip the aggregated participant-types map (e.g. 'adult' => 2)
        // that SelectionManager auto-copied onto the buyer from the order,
        // so the buyer's admin page uses the normal single-register button
        // rather than the "0/2 seats" per-type check-in table.
        if ($buyer_participant_id) {
            delete_post_meta($buyer_participant_id, 'selected_participant_types');
            delete_post_meta($buyer_participant_id, 'selected_participant_types_data');
        }
        $buyer_email = $order->get_billing_email();
        $buyer_name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        // Full address + company details from the buyer's billing block —
        // extras inherit these so their invoices, tickets, and per-participant
        // admin pages carry the same organisation context. Without this the
        // extra shows up as a standalone person with no address / company id,
        // and the ticket PDF's second column renders empty.
        $shared_company = $order->get_billing_company();
        $shared_street  = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        $shared_city    = $order->get_billing_city();
        $shared_zip     = $order->get_billing_postcode();
        $shared_country = $order->get_billing_country();
        $shared_biz_id  = (string) ($order->get_meta('_billing_company_wi_id')  ?: '');
        $shared_tax_id  = (string) ($order->get_meta('_billing_company_wi_tax') ?: '');
        $shared_vat_id  = (string) ($order->get_meta('_billing_company_wi_vat') ?: '');

        $shared_session = $order->get_meta('selected_session');
        if (is_array($shared_session)) {
            $shared_session = reset($shared_session);
        }
        $language = function_exists('pll_current_language')
            ? pll_current_language('slug')
            : substr(get_locale(), 0, 2);

        // Reuse the wired-up singleton via Core — a fresh `new Manager()` has
        // verificationManager = null, so the ticket/QR generation branch in
        // createParticipant() is silently skipped (extras end up without
        // ticket_url / qr_code_url meta and receive no ticket email).
        $manager = \Alttag\Registrations\Core::getInstance()->participantManager;

        foreach ($extras as $attendee) {
            $first = trim((string) ($attendee['first_name'] ?? ''));
            $last  = trim((string) ($attendee['last_name']  ?? ''));
            $email = trim((string) ($attendee['email']      ?? ''));
            $job   = trim((string) ($attendee['job_title']  ?? ''));

            if ($email === '' || (($first === '') && ($last === ''))) {
                continue;
            }

            $vs_prefix = apply_filters(
                'alttag_registrations_multi_participant_vs_prefix',
                apply_filters('alttag_registrations_zero_total_variable_symbol_prefix', 'EXT')
            );
            $variable_symbol = $this->generateUniqueVariableSymbol($vs_prefix);

            $participant_data = [
                'first_name'      => $first,
                'last_name'       => $last,
                'email'           => $email,
                'phone'           => '',
                'company_name'    => $shared_company,
                'street'          => $shared_street,
                'city'            => $shared_city,
                'zip'             => $shared_zip,
                'country'         => $shared_country,
                'business_id'     => $shared_biz_id,
                'tax_id'          => $shared_tax_id,
                'vat_id'          => $shared_vat_id,
                'variable_symbol' => $variable_symbol,
                'language'        => $language,
                'order_id'        => $order_id,
            ];

            $participant_id = $manager->createParticipant($participant_data);
            if (!$participant_id) {
                continue;
            }

            // SelectionManager::saveToParticipant (hooked on
            // alttag_registrations_participant_create @p:3) unconditionally
            // copies the ORDER's selected_participant_types_data (e.g.
            // 'adult' => 2) onto every participant it fires for. For extras
            // that map is wrong — each extra is a single person, so the
            // "Number of seats 0/2" per-type check-in table then shows on
            // the extra's admin page as if THEY bought 2 seats. Strip it so
            // extras use the normal single-person register button instead.
            delete_post_meta($participant_id, 'selected_participant_types');
            delete_post_meta($participant_id, 'selected_participant_types_data');

            if ($product_id) {
                update_post_meta($participant_id, 'product_id', $product_id);
                update_post_meta($participant_id, 'product_name', $ctx->productName());
            }
            if (!empty($shared_session)) {
                update_post_meta($participant_id, 'selected_session', $shared_session);
            }
            if ($job !== '') {
                update_post_meta($participant_id, 'job_title', $job);
            }
            if ($buyer_participant_id) {
                update_post_meta($participant_id, 'registered_by_participant_id', $buyer_participant_id);
            }
            if ($buyer_email) {
                update_post_meta($participant_id, 'registered_by_email', $buyer_email);
            }
            if ($buyer_name !== '') {
                update_post_meta($participant_id, 'registered_by_name', $buyer_name);
            }

            // NOTE: Do NOT fire alttag_registrations_participant_create here.
            // Manager::createParticipant already fires it internally — firing
            // again would double-run every hook on it, and SelectionManager
            // in particular would re-copy the order's aggregated
            // selected_participant_types_data (e.g. adult:2) back onto this
            // extra AFTER we deleted it above.

            // Send the extra their own ticket confirmation e-mail. The core
            // WooCommerceManager::sendCustomEmail path only fires once for
            // the buyer via handleOrderStatusChange, so without this call
            // the extras' inbox stays empty — even though their ticket PDF
            // was generated and their name appears on the buyer's roster.
            //
            // Wrapped in try/catch so a single extras' e-mail failure (e.g.
            // upstream SMTP rejects one recipient, or buildOrderEmail throws
            // on that extra's data) can't abort the loop and leave later
            // extras un-registered / un-emailed.
            \Alttag\Registrations\ParticipantState::clearCache($participant_id);
            $extra_details = $core->participantManager->getParticipantDetails($participant_id);
            if (!empty($extra_details) && !empty($extra_details['email'])) {
                $extra_order_data = $extra_details;
                $extra_order_data['participant_id'] = $participant_id;
                $extra_order_data['order_status']   = $order->get_status();
                if (!empty($extra_details['language'])) {
                    $order->update_meta_data('_language', $extra_details['language']);
                }
                // Stripe receipt is dispatched separately from
                // `woocommerce_payment_complete` — extras don't need to opt
                // out of it here because sendCustomEmail no longer sends
                // the receipt inline. Only their ticket confirmation goes.
                try {
                    $core->wooCommerceManager->sendCustomEmail($extra_order_data);
                } catch (\Throwable $e) {
                    // Swallow so a single extras' e-mail failure doesn't
                    // abort the loop and leave later extras unregistered.
                    // Log the caught throwable so it's visible in debug.log —
                    // otherwise a missing ticket / wp_mail failure for one
                    // extra is invisible.
                    error_log(sprintf(
                        '[alttag-registrations] Extras email failed for participant #%d (order #%d): %s in %s:%d',
                        $participant_id,
                        $order_id,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ));
                }

                // Post-send diagnostic: was the ticket file actually attached?
                // A missing / unreadable ticket path is silent inside wp_mail
                // (it just skips the attachment) — extras report "no ticket
                // arrived" without any exception surfacing.
                $ticket_path = $extra_details['ticket_full_file_path'] ?? '';
                if ($ticket_path === '' || !file_exists($ticket_path)) {
                    error_log(sprintf(
                        '[alttag-registrations] Extras ticket file MISSING for participant #%d (order #%d): path="%s", ticket_url="%s", ticket_file_path="%s"',
                        $participant_id,
                        $order_id,
                        $ticket_path,
                        $extra_details['ticket_url']       ?? '',
                        $extra_details['ticket_file_path'] ?? ''
                    ));
                }
            }
        }

        update_post_meta($order_id, '_alttag_extra_participants_registered', '1');
    }

    // =========================================================================
    // Admin / meta fields registration
    // =========================================================================

    public function registerMetaFields(array $fields): array
    {
        $fields['registered_by_name'] = [
            'label' => __('Registered by (name)', 'alttag-registrations'),
            'type'  => 'text',
        ];
        $fields['registered_by_email'] = [
            'label' => __('Registered by (e-mail)', 'alttag-registrations'),
            'type'  => 'email',
        ];
        $fields['job_title'] = [
            'label' => __('Job title', 'alttag-registrations'),
            'type'  => 'text',
        ];
        return $fields;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Sum the seat counters posted from ParticipantTypeSelection so validation
     * can compare against the number of extras filled. The counters post as
     * `selected_participant_types_data[<type_id>] = <count>` — either directly
     * in $_POST during native WC checkout, or serialized inside `post_data`
     * during the AJAX flow.
     */
    private function computeTotalSeatsFromPost(): int
    {
        $raw = null;
        if (isset($_POST['selected_participant_types_data']) && is_array($_POST['selected_participant_types_data'])) {
            $raw = $_POST['selected_participant_types_data'];
        } elseif (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
            parse_str($_POST['post_data'], $parsed);
            if (isset($parsed['selected_participant_types_data']) && is_array($parsed['selected_participant_types_data'])) {
                $raw = $parsed['selected_participant_types_data'];
            }
        }
        if (!is_array($raw)) {
            return 0;
        }
        $total = 0;
        foreach ($raw as $count) {
            $total += max(0, (int) $count);
        }
        return $total;
    }

    /**
     * Pull the extra_participants array from either $_POST directly or the
     * WC checkout process's serialized posted_data string.
     */
    private function extractFromPost(): array
    {
        $raw = null;
        if (isset($_POST['extra_participants']) && is_array($_POST['extra_participants'])) {
            $raw = $_POST['extra_participants'];
        } elseif (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
            parse_str($_POST['post_data'], $parsed);
            if (isset($parsed['extra_participants']) && is_array($parsed['extra_participants'])) {
                $raw = $parsed['extra_participants'];
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $attendee) {
            if (!is_array($attendee)) {
                continue;
            }
            $email = trim((string) ($attendee['email'] ?? ''));
            $first = trim((string) ($attendee['first_name'] ?? ''));
            $last  = trim((string) ($attendee['last_name']  ?? ''));
            if ($email === '' && $first === '' && $last === '') {
                continue;
            }
            $out[] = $attendee;
        }
        return array_values($out);
    }

    private function fieldLabel(string $key): string
    {
        switch ($key) {
            case 'first_name':
                return __('First name', 'alttag-registrations');
            case 'last_name':
                return __('Last name', 'alttag-registrations');
            case 'email':
                return __('E-mail', 'alttag-registrations');
            case 'job_title':
                return __('Job title', 'alttag-registrations');
        }
        return $key;
    }

    private function generateUniqueVariableSymbol(string $prefix): string
    {
        for ($i = 0; $i < 50; $i++) {
            $candidate = $prefix . mt_rand(100000, 999999);
            $exists = get_posts([
                'post_type'      => 'participant',
                'meta_key'       => 'variable_symbol',
                'meta_value'     => $candidate,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if (empty($exists)) {
                return $candidate;
            }
        }
        return $prefix . substr(str_replace('.', '', (string) microtime(true)), -8);
    }
}
