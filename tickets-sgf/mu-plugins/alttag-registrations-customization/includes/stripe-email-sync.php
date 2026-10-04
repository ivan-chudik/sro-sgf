<?php

namespace Alttag\Registrations\Customization;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stripe Link payer-email sync.
 *
 * A customer can type one address into the checkout billing field and then pay
 * through Stripe Link with a different account (Savel case: form said
 * saveljuraj81@…, the payment came from kilcohan@…). WooCommerce keeps the
 * form value, so the participant record and every confirmation e-mail go to an
 * address that never paid and may not belong to the buyer at all.
 *
 * The paying address lives on the PaymentIntent (`receipt_email`) or on its
 * charge (`billing_details->email`). We copy it onto the order BEFORE the
 * participant is created.
 *
 * Ordering: `WC_Order::payment_complete()` fires
 * `woocommerce_pre_payment_complete` (class-wc-order.php:143) and only then
 * saves the order — the save triggers `woocommerce_order_status_changed`,
 * which is where WooCommerceManager::handleOrderStatusChange (prio 10) creates
 * the participant and sends the confirmation e-mail, and where
 * MultiParticipantModule::onOrderStatusChanged (prio 20) syncs extras.
 * `woocommerce_payment_complete` (line 185, StripeManager receipt at prio 100)
 * is already too late for participant creation, so the sync runs primarily on
 * the `pre` hook. It is also registered on `woocommerce_payment_complete` at
 * prio 5 as an idempotent safety net for gateways that persist
 * `_stripe_intent_id` only after `pre` has fired — in that case the order and
 * the already-created participant are both repaired before the receipt goes
 * out.
 */

/**
 * Extract the paying e-mail from a PaymentIntent-like structure.
 *
 * Pure and side-effect free so it can be tested without touching the Stripe
 * API. Accepts objects (Stripe SDK / WC_Stripe_API responses) or arrays.
 *
 * @param object|array|null $intent
 * @return string Empty string when no usable e-mail is present.
 */
function stripe_payer_email_from_intent($intent): string
{
    $get = static function ($node, $key) {
        if (is_array($node)) {
            return $node[$key] ?? null;
        }
        if (is_object($node)) {
            return $node->$key ?? null;
        }
        return null;
    };

    if (!is_array($intent) && !is_object($intent)) {
        return '';
    }

    $email = $get($intent, 'receipt_email');
    if (is_string($email) && is_email($email)) {
        return $email;
    }

    // Modern API shape: `latest_charge` expanded into a charge object.
    // Unexpanded it is a bare charge ID string and carries no e-mail.
    $charge = $get($intent, 'latest_charge');
    if (!is_array($charge) && !is_object($charge)) {
        // Legacy shape: charges->data[0]
        $charges = $get($intent, 'charges');
        $data = $charges ? $get($charges, 'data') : null;
        $charge = is_array($data) ? ($data[0] ?? null) : null;
    }

    if (is_array($charge) || is_object($charge)) {
        $details = $get($charge, 'billing_details');
        $email = $details ? $get($details, 'email') : null;
        if (is_string($email) && is_email($email)) {
            return $email;
        }
    }

    return '';
}

/**
 * Retrieve the PaymentIntent for an order, expanding the latest charge.
 *
 * Mirrors StripeManager::getLatestCharge (StripeManager.php:344) — both Stripe
 * gateways may be installed, so support each. Returns null on any failure;
 * callers must treat that as "leave the order alone".
 *
 * @param \WC_Order $order
 * @return object|array|null
 */
function stripe_fetch_intent_for_order(\WC_Order $order)
{
    // New plugin (woo-stripe-payment) uses _payment_intent_id,
    // old plugin (woocommerce-gateway-stripe) uses _stripe_intent_id.
    $intent_id = $order->get_meta('_payment_intent_id');
    if (empty($intent_id)) {
        $intent_id = $order->get_meta('_stripe_intent_id');
    }
    if (empty($intent_id)) {
        return null;
    }

    if (class_exists('WC_Stripe_Gateway')) {
        $intent = \WC_Stripe_Gateway::load()->fetch_payment_intent($intent_id);
        if (is_wp_error($intent) || !$intent) {
            return null;
        }
        // fetch_payment_intent() does not expand charges; pull the charge in
        // separately when receipt_email is absent.
        if (empty(stripe_payer_email_from_intent($intent))) {
            $charge_id = null;
            if (!empty($intent->latest_charge)) {
                $charge_id = is_object($intent->latest_charge)
                    ? ($intent->latest_charge->id ?? null)
                    : $intent->latest_charge;
            }
            if ($charge_id && method_exists(\WC_Stripe_Gateway::load(), 'get_charge')) {
                $charge = \WC_Stripe_Gateway::load()->get_charge($charge_id);
                if (!is_wp_error($charge) && $charge) {
                    $intent->latest_charge = $charge;
                }
            }
        }
        return $intent;
    }

    if (class_exists('WC_Stripe_API')) {
        $intent = \WC_Stripe_API::request(
            ['expand' => ['latest_charge']],
            "payment_intents/{$intent_id}",
            'GET'
        );
        if (!empty($intent->error)) {
            return null;
        }
        return $intent;
    }

    return null;
}

/**
 * Move an already-created participant onto the new e-mail.
 *
 * Only touched when the participant still carries the stale address, so a
 * second run (pre + payment_complete) is a no-op.
 *
 * @return int|null Participant post ID that was updated, or null.
 */
function stripe_sync_participant_email(int $order_id, string $old_email, string $new_email): ?int
{
    if (!class_exists('\Alttag\Registrations\Core')) {
        return null;
    }

    $manager = \Alttag\Registrations\Core::getInstance()->participantManager ?? null;
    if (!$manager) {
        return null;
    }

    $participant = $manager->getParticipantByOrderId($order_id);
    if (empty($participant) && method_exists($manager, 'getParticipantByEmail')) {
        $participant = $manager->getParticipantByEmail($old_email);
    }
    if (empty($participant) || empty($participant->ID)) {
        return null;
    }

    $post_id = (int) $participant->ID;
    if (get_post_meta($post_id, 'email', true) === $new_email) {
        return null;
    }

    update_post_meta($post_id, 'email', $new_email);

    // Titles are "First Last" (Participant/Manager.php:366), but imported or
    // livestream records can carry the e-mail in the title — swap it there too.
    if ($old_email !== '' && strpos($participant->post_title, $old_email) !== false) {
        wp_update_post([
            'ID' => $post_id,
            'post_title' => str_replace($old_email, $new_email, $participant->post_title),
        ]);
    }

    return $post_id;
}

/**
 * Copy the paying e-mail from Stripe onto the order (and its participant).
 *
 * @param int                    $order_id
 * @param object|array|null      $intent Injected intent, for tests. When null
 *                                       the intent is fetched from Stripe.
 * @return bool True when the order e-mail was changed.
 */
function stripe_sync_payer_email($order_id, $intent = null): bool
{
    try {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return false;
        }

        if ($intent === null) {
            $intent = stripe_fetch_intent_for_order($order);
        }

        $payer_email = stripe_payer_email_from_intent($intent);
        if ($payer_email === '') {
            return false;
        }

        $old_email = (string) $order->get_billing_email();
        if (strcasecmp($old_email, $payer_email) === 0) {
            return false;
        }

        $order->set_billing_email($payer_email);
        $order->add_order_note(sprintf(
            /* translators: 1: previous billing e-mail, 2: e-mail used to pay */
            __(
                'Billing e-mail replaced with the address used to pay via Stripe: %1$s → %2$s',
                'alttag-registrations-customization'
            ),
            $old_email !== '' ? $old_email : '—',
            $payer_email
        ));
        $order->save();

        stripe_sync_participant_email((int) $order->get_id(), $old_email, $payer_email);

        return true;
    } catch (\Throwable $e) {
        // Never break the payment flow — keep the original e-mail.
        return false;
    }
}

// Runs before the order is saved, i.e. before the participant is created.
// accepted_args defaults to 1, so $intent stays null and is fetched from Stripe.
add_action('woocommerce_pre_payment_complete', __NAMESPACE__ . '\\stripe_sync_payer_email', 5);
// Safety net for gateways that write the intent meta after `pre`.
add_action('woocommerce_payment_complete', __NAMESPACE__ . '\\stripe_sync_payer_email', 5);
