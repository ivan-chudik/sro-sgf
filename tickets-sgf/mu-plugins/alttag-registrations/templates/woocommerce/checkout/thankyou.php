<?php
/**
 * Thankyou page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/thankyou.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.1.0
 *
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;
?>

<div class="woocommerce-order checkout-thankyou">
    <?php if ($order) : ?>
        <?php do_action('woocommerce_before_thankyou', $order->get_id()); ?>

        <?php if ($order->has_status('failed')) : ?>
            <p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed">
                <?php esc_html_e('Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce'); ?>
            </p>

            <p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
                <a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="button pay">
                    <?php esc_html_e('Pay', 'woocommerce'); ?>
                </a>
            </p>
        <?php else : ?>
            <?php
            $ctx = \Alttag\Registrations\RegistrationContext::forOrder($order);
            $pState = $ctx->participant();
            $invoice_url = $order->get_meta('wc_sf_invoice_regular');
            $ticket_url = $pState ? $pState->ticket_url : '';
            $variable_symbol = $pState ? $pState->variable_symbol : '';
            ?>

            <div>
                <?php
                if ($order->has_status('completed') && $order->get_total() > 0) {
                    $heading = __('Thank you for your payment and registration!', 'alttag-registrations');
                } else {
                    $heading = __('Thank you for registration!', 'alttag-registrations');
                }
            $heading = apply_filters('alttag_registrations_checkout_heading', $heading, $order);
            ?>
                <h2><?php echo esc_html($heading); ?></h2>

                <?php
            // Allow customization of greeting
            do_action('alttag_registrations_checkout_before_greeting', $order);
            ?>

                <p>
                    <?php
                echo wp_kses_post(apply_filters('alttag_registrations_checkout_greeting', sprintf(
                    __('Dear %s %s,', 'alttag-registrations'),
                    esc_html($order->get_billing_first_name()),
                    esc_html($order->get_billing_last_name())
                ), $order));
            ?>
                </p>

                <?php do_action('alttag_registrations_checkout_after_greeting', $order); ?>

                <?php
                $show_event_details = apply_filters('alttag_registrations_checkout_show_event_details', true, $order);
            if ($show_event_details) :
                $event_details_text = apply_filters(
                    'alttag_registrations_checkout_event_details_text',
                    $ctx->thankYouEventDetailsText(),
                    $order,
                    $ctx
                );
                ?>
                <p>
                    <?php echo wp_kses_post($event_details_text); ?>
                </p>
                <?php endif; ?>

                <?php
                $confirmation_text = apply_filters(
                    'alttag_registrations_checkout_confirmation_message',
                    $ctx->thankYouConfirmationMessage($order),
                    $order,
                    $ctx
                );
?>
                <p><?php echo wp_kses_post($confirmation_text); ?></p>
                
                <?php
// Handle QR code and livestream messages based on order contents
$show_qr_section = apply_filters('alttag_registrations_checkout_show_qr_section', true, $order);
if ($show_qr_section) :
    $order_contains_online_attendance = $ctx->orderHasLivestream();
    $order_contains_inperson = $ctx->orderHasInperson();

    if ($order_contains_online_attendance) {
        // Show livestream instructions
        $noun_genitive = esc_html($ctx->eventNounGenitive());
        if ($order->get_status() !== 'completed') {
            echo '<p>' . sprintf(
                /* translators: 1: Stream URL, 2: Stream link text, 3: event noun in genitive case (e.g. "podujatia") */
                __('An email to create a password will be sent after payment of the order. After creating it, you will be able to log in to the <a href="%1$s" target="_blank">%2$s</a> page with your email and created password to watch the online stream of the %3$s.', 'alttag-registrations'),
                esc_url($ctx->streamUrl()),
                esc_html($ctx->streamLinkText()),
                $noun_genitive
            ) . '</p><p>' . sprintf(
                /* translators: %s: event noun in genitive case */
                __('You will be able to watch the online stream of the %s from only one device. If you log in from another device, you will be automatically logged out and will have to log in again.', 'alttag-registrations'),
                $noun_genitive
            ) . '</p>';
        } else {
            echo '<p>' . sprintf(
                /* translators: 1: Stream URL, 2: Stream link text, 3: event noun in genitive case */
                __('An email to create a password will be sent. After creating it, you will be able to log in to the <a href="%1$s" target="_blank">%2$s</a> page with your email and created password to watch the online stream of the %3$s. Please also check your spam folder.', 'alttag-registrations'),
                esc_url($ctx->streamUrl()),
                esc_html($ctx->streamLinkText()),
                $noun_genitive
            ) . '</p><p>' . sprintf(
                /* translators: %s: event noun in genitive case */
                __('You can watch the online stream of the %s from only one device. If you log in from another device, you will be automatically logged out and will have to log in again.', 'alttag-registrations'),
                $noun_genitive
            ) . '</p>';
        }
    }

if ($order_contains_inperson) {
    // Show QR code instructions for in-person products
    $accent_color = apply_filters('alttag_registrations_thankyou_accent_color', '#ed1c24');
    $qr_code_paragraph = '<p>' . sprintf(
        /* translators: 1: Event name (accusative), 2: Event date, 3: Event location, 4: accent color */
        __('When entering the <strong>%1$s</strong> on <strong>%2$s</strong> at <strong>%3$s</strong>, please show your <span style="color:%4$s; font-weight: bold;">QR code</span> from the email.', 'alttag-registrations'),
        esc_html($ctx->eventNameAccusative()),
        esc_html($ctx->eventDatesString()),
        $ctx->eventLocationWithMap('locative'),
        esc_attr($accent_color)
    ) . '</p>';
    $qr_code_paragraph = apply_filters(
        'alttag_registrations_checkout_qr_paragraph',
        $qr_code_paragraph,
        $order,
        $ctx
    );
    echo wp_kses_post($qr_code_paragraph);
}
endif;
?>

                <?php if ($order->get_payment_method() === 'invoice_payment' && $variable_symbol) :
                    $payment_accent = apply_filters('alttag_registrations_thankyou_accent_color', '#ed1c24');
                    ?>
                    <div class="payment-instructions" style="margin: 20px 0; padding: 15px; background: #f7f7f7; border-left: 4px solid <?php echo esc_attr($payment_accent); ?>;">
                        <p style="margin: 0; font-weight: bold;">
                            <?php
                                echo sprintf(
                                    __('Important: When making the bank transfer, please use the variable symbol: %s', 'alttag-registrations'),
                                    '<span style="color: ' . esc_attr($payment_accent) . ';">' . esc_html($variable_symbol) . '</span>'
                                );
                    ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php
                // Multi-participant checkout: list every attendee who belongs
                // to this order (buyer + extras) so the person paying can
                // confirm the roster. Extras receive their tickets by email —
                // the download button below is only for the buyer.
                $order_id_for_attendees = $order->get_id();
$attendee_posts = get_posts([
    'post_type'      => 'participant',
    'meta_key'       => 'order_id',
    'meta_value'     => $order_id_for_attendees,
    'posts_per_page' => -1,
    'orderby'        => 'ID',
    'order'          => 'ASC',
]);
if (count($attendee_posts) > 1) : ?>
                    <div class="checkout-thankyou-attendees" style="margin: 20px 0; padding: 15px; background: #f7f7f7; border-radius: 4px;">
                        <p style="margin: 0 0 10px; font-weight: bold;">
                            <?php esc_html_e('Registered attendees:', 'alttag-registrations'); ?>
                        </p>
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($attendee_posts as $ap) :
                                $ap_state = \Alttag\Registrations\ParticipantState::get($ap->ID);
                                if (!$ap_state) {
                                    continue;
                                }
                                $ap_first    = $ap_state->first_name;
                                $ap_last     = $ap_state->last_name;
                                $ap_email    = $ap_state->email;
                                $is_buyer    = empty($ap_state->getMeta('registered_by_participant_id'));
                                $ap_job      = $ap_state->job_title;
                                $name_line   = trim($ap_first . ' ' . $ap_last);
                                if ($name_line === '') {
                                    $name_line = $ap_email;
                                }
                                ?>
                                <li style="margin-bottom: 4px;">
                                    <strong><?php echo esc_html($name_line); ?></strong>
                                    <?php if (!empty($ap_job)) : ?>
                                        <span style="color:#666;">— <?php echo esc_html($ap_job); ?></span>
                                    <?php endif; ?>
                                    <span style="color:#666;"> · <?php echo esc_html($ap_email); ?></span>
                                    <?php if ($is_buyer) : ?>
                                        <em style="color:#888;"> (<?php esc_html_e('you', 'alttag-registrations'); ?>)</em>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p style="margin: 10px 0 0; color:#555; font-size: 14px;">
                            <?php esc_html_e('Additional attendees will receive their own ticket at the e-mail address listed above.', 'alttag-registrations'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php
                $display_download_links = apply_filters('alttag_registrations_display_download_links', true);

if ($display_download_links && ($invoice_url || $ticket_url)) : ?>
                    <div class="checkout-thankyou-download-links" style="display: flex; gap: 20px; justify-content: flex-start; margin: 20px 0;">

                        <?php $display_invoice_download_link = apply_filters('alttag_registrations_display_invoice_download_link', true, $order); ?>
                        <?php if ($display_invoice_download_link && $invoice_url) : ?>
                            <a class="elementor-button elementor-button-link elementor-size-sm elementor-animation-grow" 
                               href="<?php echo esc_url($invoice_url); ?>" 
                               style="text-decoration: none; text-transform: uppercase; font-weight: bold;">
                                <span class="elementor-button-content-wrapper">
                                    <span class="elementor-button-text">
                                        <?php _e('Download invoice', 'alttag-registrations'); ?>
                                    </span>
                                </span>
                            </a>
                        <?php endif; ?>

                        <?php $display_ticket_download_link = apply_filters('alttag_registrations_display_ticket_download_link', true, $order); ?>
                        <?php if ($display_ticket_download_link && $ticket_url) : ?>
                            <a class="elementor-button elementor-button-link elementor-size-sm elementor-animation-grow" 
                               href="<?php echo esc_url($ticket_url); ?>" 
                               download
                               style="text-decoration: none; text-transform: uppercase; font-weight: bold;">
                                <span class="elementor-button-content-wrapper">
                                    <span class="elementor-button-text">
                                        <?php _e('Download ticket', 'alttag-registrations'); ?>
                                    </span>
                                </span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php do_action('alttag_registrations_checkout_after_download_links', $order); ?>

                <p>
                    <?php _e('Have a nice day!', 'alttag-registrations'); ?><br>
                    <?php echo esc_html($ctx->teamName($order)); ?>
                </p>
            </div>

            <?php wc_get_template('checkout/order-received.php', array('order' => $order)); ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php do_action('alttag_registrations_thankyou_end', $order); ?>
