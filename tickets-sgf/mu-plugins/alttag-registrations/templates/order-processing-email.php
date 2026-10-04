<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Available variables:
 * $first_name - Customer's first name
 * $last_name - Customer's last name
 * $email - Customer's email
 * $order_id - Order ID
 * $company_name - Company name
 * $street - Street address
 * $city - City
 * $zip - ZIP code
 * $country - Country
 * $qr_code_url - QR code image url
 *
 * NOTE: This template content will be wrapped with an Outlook-compatible HTML structure
 * by the EmailWrapper class. Do not include <!DOCTYPE>, <html>, <head>, or <body> tags here.
 */

// Pass participant_id to title filter so it can check participant meta (for imported participants)
$email_title = apply_filters('alttag_registrations_email_title', '', $order, $participant_id ?? null);
$primary_color = apply_filters('alttag_registrations_email_primary_color', '#2B5C63');
$accent_color = apply_filters('alttag_registrations_email_accent_color', '#FFE500');
$bg_color = apply_filters('alttag_registrations_email_background_color', '#f8f9fa');
$dark_primary_color = apply_filters('alttag_registrations_email_dark_primary_color', '#1f4349');

// Get name from order or from variables (for imported participants without order)
$greeting_first_name = $order ? $order->get_billing_first_name() : ($first_name ?? '');
$greeting_last_name = $order ? $order->get_billing_last_name() : ($last_name ?? '');
?>
<div class="header" style="background: <?php echo $primary_color; ?>; padding: 40px 30px; text-align: center; margin: 0; border-radius: 12px 12px 0 0; width: 100%; box-sizing: border-box;">
    <h1 style="color: <?php echo $accent_color; ?>; font-family: Arial, Helvetica, sans-serif; font-size: 22px; font-weight: 600; margin: 0; text-align: center; letter-spacing: 0.3px; line-height: 1.5; width: 100%; box-sizing: border-box;"><?php echo $email_title; ?></h1>
</div>

<div style="width: 100%; box-sizing: border-box; padding: 35px 40px 35px 40px; margin: 0; background-color: #ffffff;">
    <p style="width: 100%; box-sizing: border-box; margin: 0 0 25px 0; padding: 0; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.7; color: <?php echo $primary_color; ?>;"><?php
        echo \Alttag\Registrations\email_kses(apply_filters('alttag_registrations_email_greeting', sprintf(
            __('Dear %s %s,', 'alttag-registrations'),
            esc_html($greeting_first_name),
            esc_html($greeting_last_name)
        ), $order));
    ?></p>

    <?php
    $ctx = \Alttag\Registrations\RegistrationContext::current();
    $event_details = apply_filters('alttag_registrations_email_event_details', '', $order, $participant_id ?? null);
    // Per-product: hide variable symbol from email and ticket
    $_email_product_id = 0;
    if ($order) {
        foreach ($order->get_items() as $_item) {
            $_email_product_id = (int) $_item->get_product_id();
            break;
        }
    }
    $_hide_vs = $_email_product_id && get_post_meta($_email_product_id, '_alttag_hide_variable_symbol', true) === 'yes';
    $effective_order_status = $order ? $order_status : 'completed';
    if ($effective_order_status === 'completed'):
        // Check if this is a livestream user
        $is_livestream_user = false;
        if ($ctx->isLivestreamEnabled()) {
            $pState = null;
            if ($order) {
                $pState = \Alttag\Registrations\get_participant_state_by_order($order->get_id());
            } elseif (isset($participant_id)) {
                $pState = \Alttag\Registrations\ParticipantState::get($participant_id);
            }
            if ($pState) {
                $is_livestream_user = $pState->isLivestream();
            }
        }

        // For free orders or no order (imported participants), use registration text instead of payment text
        $is_free_or_no_order = !$order || (float) $order->get_total() === 0.0;
        if ($is_free_or_no_order) {
            if ($is_livestream_user) {
                $default_text = sprintf(__('Thank you for your registration for %s.', 'alttag-registrations'), $event_details);
            } else {
                $default_text = sprintf(__('Thank you for your registration for the event %s.', 'alttag-registrations'), $event_details);
            }
        } else {
            // Normal payment flow
            if ($is_livestream_user) {
                $default_text = sprintf(__('Thank you for paying for %s.', 'alttag-registrations'), $event_details);
            } else {
                $default_text = sprintf(__('Thank you for paying for the entrance to %s.', 'alttag-registrations'), $event_details);
            }
        }
    ?>
        <p style="width: 100%; box-sizing: border-box; margin: 0 0 25px 0; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.7; color: <?php echo $primary_color; ?>;"><?php echo \Alttag\Registrations\email_kses(apply_filters('alttag_registrations_email_payment_confirmation_text', $default_text, $order)); ?></p>
    <?php else: ?>
        <p style="width: 100%; box-sizing: border-box; margin: 0 0 25px 0; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.7; color: <?php echo $primary_color; ?>;"><?php echo \Alttag\Registrations\email_kses(apply_filters('alttag_registrations_email_registration_confirmation_text', sprintf(
            __('Thank you for registering for the event %s.', 'alttag-registrations'),
            $event_details
        ), $order)); ?></p>
    <?php endif; ?>

    <?php do_action('alttag_registrations_email_after_intro', $order, $participant_id ?? null); ?>

    <?php if ($payment_method === 'bank_transfer' && $variable_symbol && !$_hide_vs) : ?>
        <div class="payment-instructions" style="width: 100%; box-sizing: border-box; background: linear-gradient(135deg, #e8f4f5 0%, #f0f8f9 100%); border-left: 4px solid <?php echo $primary_color; ?>; padding: 20px 25px; margin: 0 0 30px 0; border-radius: 6px;">
            <p style="color: <?php echo $primary_color; ?>; width: 100%; box-sizing: border-box; margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.6; padding: 0; font-weight: 500;">
                <?php
                echo \Alttag\Registrations\email_kses(apply_filters('alttag_registrations_email_payment_instructions', sprintf(
                    __('Important: When making the bank transfer, please use the variable symbol: %s', 'alttag-registrations'),
                    '<span style="color: ' . $dark_primary_color . '; font-weight: 700;">' . esc_html($variable_symbol) . '</span>'
                ), $order));
        ?>
            </p>
        </div>
    <?php endif; ?>

    <?php do_action('alttag_registrations_email_after_payment_instructions', $order); ?>

    <?php $detail_style = 'width: 100%; box-sizing: border-box; margin: 0 0 10px 0; color: ' . $primary_color . '; font-size: 14px; font-family: Arial, Helvetica, sans-serif; line-height: 1.6;'; ?>
    <p style="width: 100%; box-sizing: border-box; margin: 0 0 15px 0; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.7; color: <?php echo $primary_color; ?>; font-weight: 600;"><?php _e('Your order details:', 'alttag-registrations'); ?></p>
    <div style="margin: 0 0 30px 0; padding: 20px 25px; background: <?php echo $bg_color; ?>; border-radius: 6px; border-left: 3px solid <?php echo $primary_color; ?>;">
        <?php do_action('alttag_registrations_email_before_custom_fields', $order); ?>
        <?php
        $detail_rows = \Alttag\Registrations\FieldBuilder::getEmailDetailRows([
            'first_name' => $first_name, 'last_name' => $last_name,
            'email' => $email, 'phone' => $phone,
            'company_name' => $company_name,
            'street' => $street, 'city' => $city, 'zip' => $zip,
            'country_name' => $country_name,
            'business_id' => $business_id ?? '', 'vat_id' => $vat_id ?? '',
            'tax_id' => $tax_id ?? '', 'variable_symbol' => $variable_symbol,
            'order_comments' => $order_comments ?? '',
        ], $order, $participant_id ?? null, $_hide_vs);

        foreach ($detail_rows as $_row) : ?>
            <div style="<?php echo $detail_style; ?>"><?php
                echo esc_html($_row['label']) . ': ';
                echo !empty($_row['nl2br']) ? nl2br(esc_html($_row['value'])) : esc_html($_row['value']);
            ?></div>
        <?php endforeach; ?>

        <?php
        /**
         * Hook to add custom event-specific fields (session details, accommodation, companion)
         */
        do_action('alttag_registrations_email_after_custom_fields', $order, $participant_id ?? null);
        ?>
    </div>

    <?php do_action('alttag_registrations_email_after_order_details', $order, $participant_id ?? null); ?>

    <?php
    $display_qr_code = apply_filters('alttag_registrations_email_display_qr_code', true, $order, $participant_id ?? null);
    if ($display_qr_code) { ?>
        <div class="qr-code" style="width: 100%; box-sizing: border-box; padding: 30px 0; text-align: center; margin: 0;">
            <p style="color: <?php echo $primary_color; ?>; width: 100%; box-sizing: border-box;  font-family: Arial, Helvetica, sans-serif; font-size: 16px; margin: 0 0 20px 0; padding: 0; line-height: 1.7; font-weight: 600;"><?php _e('Your QR Code Ticket:', 'alttag-registrations'); ?></p>

            <?php if ($qr_code_url): ?>
                <img src="<?php echo htmlspecialchars($qr_code_url); ?>" alt="<?php _e('QR Code Ticket', 'alttag-registrations'); ?>" style="border: 4px solid <?php echo $primary_color; ?>; padding: 15px; background: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(43, 92, 99, 0.15); max-width: 250px; margin: 0 auto 20px auto; display: block;">
                <p style="width: 100%; box-sizing: border-box; color: <?php echo $primary_color; ?>; font-family: Arial, Helvetica, sans-serif; font-size: 14px; margin: 0; padding: 0; line-height: 1.7;"><?php echo sprintf(
                    __('If the QR code is not displayed, you can find it in the attached ticket or %sdownload here%s.', 'alttag-registrations'),
                    '<a href="' . htmlspecialchars($qr_code_url) . '" style="color: ' . $primary_color . '; font-weight: 600; text-decoration: underline;">',
                    '</a>'
                ); ?></p>
            <?php else: ?>
                <p style="width: 100%; box-sizing: border-box; color: <?php echo $primary_color; ?>; font-family: Arial, Helvetica, sans-serif; font-size: 14px; margin: 0; padding: 0; line-height: 1.7;"><?php _e('QR code will be generated after payment confirmation.', 'alttag-registrations'); ?></p>
            <?php endif; ?>
        </div>

        <p style="margin: 0 0 30px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.7; color: <?php echo $primary_color; ?>; padding: 15px 20px; background-color: <?php echo $bg_color; ?>; border-radius: 6px; border-left: 3px solid <?php echo $primary_color; ?>;"><?php _e('Please keep this email for your records.<br>You will need to present the QR code when attending the event.', 'alttag-registrations'); ?></p>
    <?php } ?>
</div>

<?php do_action('alttag_registrations_email_before_footer', $order); ?>

<div class="footer" style="width: 100%; box-sizing: border-box; background: <?php echo $primary_color; ?>; color: #ffffff; padding: 30px 40px; text-align: center; margin: 0; border-radius: 0 0 12px 12px; display: block;">
    <?php
    $footer_info_text = $ctx->footerInfoText();
    if (!empty($footer_info_text)) {
        echo '<p style="width: 100%; box-sizing: border-box; color: #ffffff; margin: 0 0 15px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.6; padding: 0; display: block; width: 100%; box-sizing: border-box;">' . \Alttag\Registrations\email_kses($footer_info_text) . '</p>';
    }

    $organization_name = $ctx->teamName($order);
    if (!empty($organization_name)) {
        echo '<p style="width: 100%; box-sizing: border-box; color: #ffffff; margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.6; padding: 0; display: block; width: 100%; box-sizing: border-box;"><b style="color: ' . $accent_color . '; font-weight: 700; font-size: 17px;">' . esc_html($organization_name) . '</b></p>';
    }
    ?>
</div>