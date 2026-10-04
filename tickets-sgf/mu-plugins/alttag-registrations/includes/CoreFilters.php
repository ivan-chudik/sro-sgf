<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers default filter callbacks that read from Settings DB.
 * All registered at priority 5 so customization plugins at priority 10+ override.
 */
class CoreFilters
{
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function registerHooks()
    {
        // General / Organization
        add_filter('alttag_registrations_event_organization_name', [$this, 'getOrgName'], 5);
        add_filter('alttag_registrations_event_organization_team_name', [$this, 'getTeamName'], 5);
        add_filter('alttag_registrations_event_organization_contact_email', [$this, 'getContactEmail'], 5);
        add_filter('alttag_registrations_mail_from', [$this, 'getMailFrom'], 5);
        add_filter('alttag_registrations_default_language', [$this, 'getDefaultLanguage'], 5);

        // Email colors
        add_filter('alttag_registrations_email_primary_color', [$this, 'getPrimaryColor'], 5);
        add_filter('alttag_registrations_email_accent_color', [$this, 'getAccentColor'], 5);
        add_filter('alttag_registrations_email_background_color', [$this, 'getBackgroundColor'], 5);
        add_filter('alttag_registrations_email_dark_primary_color', [$this, 'getDarkPrimaryColor'], 5);
        add_filter('alttag_registrations_email_greeting', [$this, 'getEmailGreeting'], 5, 2);
        add_filter('alttag_registrations_email_title', [$this, 'getEmailTitle'], 5, 3);
        add_filter('alttag_registrations_email_subject', [$this, 'getEmailSubject'], 5, 3);
        add_filter('alttag_registrations_email_event_details', [$this, 'getEmailEventDetails'], 5, 3);
        add_filter('alttag_registrations_receipt_product_title', [$this, 'getReceiptProductTitle'], 5, 2);
        add_filter('alttag_registrations_email_display_qr_code', [$this, 'shouldDisplayEmailQrCode'], 5, 3);
        add_action('alttag_registrations_email_after_order_details', [$this, 'renderLivestreamEmailInfo'], 5, 2);
        add_action('alttag_registrations_email_after_order_details', [$this, 'renderInpersonEmailInfo'], 7, 2);
        // Email detail rows are now rendered by FieldBuilder::getEmailDetailRows()
        // called directly from the email template. No separate hook needed.

        // Email text
        add_filter('alttag_registrations_info_text', [$this, 'getInfoText'], 5);
        add_filter('alttag_registrations_footer_info_text', [$this, 'getFooterInfoText'], 5);

        // Generic non-membership overrides (membership variants live in MembershipModule)
        add_filter('alttag_registrations_email_title', [$this, 'maybeNonMembershipEmailTitle'], 7, 3);
        add_filter('alttag_registrations_email_event_details', [$this, 'maybeNonMembershipEventDetails'], 7, 3);
        add_filter('alttag_registrations_email_subject', [$this, 'maybeLivestreamSubjectReplace'], 7, 3);
        add_action('alttag_registrations_email_styles', [$this, 'outputEmailCustomCss'], 99);

        // Checkout
        add_filter('alttag_registrations_enable_gdpr_consent', [$this, 'isGdprEnabled'], 5);
        add_filter('alttag_registrations_enable_terms_consent', [$this, 'isTermsEnabled'], 5);
        add_filter('alttag_registrations_terms_link', [$this, 'getTermsUrl'], 5);
        add_filter('alttag_registrations_gdpr_link', [$this, 'getGdprUrl'], 5);
        add_filter('alttag_registrations_skip_email_duplicate_check', [$this, 'isSkipEmailDuplicate'], 5, 2);
        add_filter('alttag_registrations_enable_multi_day_duplicate_validation', [$this, 'isMultiDayDuplicateValidationEnabled'], 5);
        add_filter('alttag_registrations_display_invoice_download_link', [$this, 'displayInvoiceDownloadLink'], 5);
        add_filter('alttag_registrations_enable_extended_participant_features', [$this, 'isExtendedParticipantFeaturesEnabled'], 5);
        add_filter('alttag_registrations_enable_multi_day_participant_features', [$this, 'isMultiDayParticipantFeaturesEnabled'], 5);

        // Livestream
        add_filter('alttag_registrations_enable_livestream', [$this, 'isLivestreamEnabled'], 5);
        add_filter('alttag_registrations_stream_url', [$this, 'getStreamUrl'], 5);
        add_filter('alttag_registrations_stream_link_text', [$this, 'getStreamLinkText'], 5);
        add_filter('alttag_registrations_livestream_webhook_url', [$this, 'getWebhookUrl'], 5);

        // Livestream detection using core helpers
        add_filter('alttag_registrations_is_livestream_user', [$this, 'isLivestreamUser'], 5, 2);
        add_filter('alttag_registrations_has_online_attendance_product', [$this, 'hasOnlineProduct'], 5, 2);
        add_filter('alttag_registrations_cart_contains_online_attendance_product', [$this, 'cartHasOnlineProduct'], 5);
        add_filter('alttag_registrations_order_has_only_livestream_product', [$this, 'orderHasOnlyLivestream'], 5, 2);
        add_filter('alttag_registrations_is_participant_livestream_user', [$this, 'isParticipantLivestream'], 5, 2);

        // Checkout: order notes
        if ($this->settings->get('checkout.disable_order_notes')) {
            add_filter('woocommerce_enable_order_notes_field', '__return_false');
        }
    }

    // =========================================================================
    // General
    // =========================================================================

    public function getOrgName($name)
    {
        return Settings::getValue('general.org_name') ?: $name;
    }

    public function getTeamName($name)
    {
        return Settings::getValue('general.org_team_name') ?: $name;
    }

    public function getContactEmail($email)
    {
        return Settings::getValue('general.contact_email') ?: $email;
    }

    public function getMailFrom($email)
    {
        return $this->settings->get('general.mail_from', '') ?: $email;
    }

    public function getDefaultLanguage($lang)
    {
        return $this->settings->get('general.default_language', '') ?: $lang;
    }

    // =========================================================================
    // Email
    // =========================================================================

    public function getPrimaryColor($color)
    {
        return $this->settings->get('email.primary_color', '') ?: $color;
    }

    public function getAccentColor($color)
    {
        return $this->settings->get('email.accent_color', '') ?: $color;
    }

    public function getBackgroundColor($color)
    {
        return $this->settings->get('email.background_color', '') ?: $color;
    }

    public function getDarkPrimaryColor($color)
    {
        return $this->settings->get('email.dark_primary_color', '') ?: $color;
    }

    public function getEmailGreeting($greeting, $order)
    {
        return ctx()->withOrder($order)->emailGreeting($greeting);
    }

    public function getEmailTitle($title, $order, $participant_id = null)
    {
        $context = $participant_id
            ? ctx()->withParticipant($participant_id)
            : ctx()->withOrder($order);

        return $context->emailTitleText(true);
    }

    public function getEmailSubject($subject, $participant, $participant_id)
    {
        if (!empty($subject)) {
            return $subject;
        }

        return ctx()->withParticipant($participant_id)->emailTitleText(false);
    }

    public function getEmailEventDetails($content, $order, $participant_id = null)
    {
        $context = $participant_id
            ? ctx()->withParticipant($participant_id)
            : ctx()->withOrder($order);

        $details = $context->emailEventDetails();
        return $details !== '' ? $details : $content;
    }

    public function getReceiptProductTitle($title, $order)
    {
        $value = ctx()->withOrder($order)->receiptProductTitle($order);
        return $value !== '' ? $value : $title;
    }

    public function shouldDisplayEmailQrCode($display, $order, $participant_id = null)
    {
        $context = $participant_id
            ? ctx()->withParticipant($participant_id)
            : ctx()->withOrder($order);

        return $context->shouldDisplayEmailQrCode();
    }

    public function renderLivestreamEmailInfo($order, $participant_id = null)
    {
        $context = $participant_id
            ? ctx()->withParticipant($participant_id)
            : ctx()->withOrder($order);

        // Mirrors `alttag_registrations_email_display_qr_code`: lets a site drop
        // the livestream password / one-device paragraphs for orders that are
        // not live (e.g. recording-archive purchases) without unhooking this.
        $display = apply_filters(
            'alttag_registrations_email_display_livestream_info',
            $context->hasLivestreamEmailInfo(),
            $order,
            $participant_id
        );

        if (!$display) {
            return;
        }

        $texts = $context->emailLivestreamInfoTexts();
        echo '<p>' . wp_kses_post($texts['login']) . '</p>';
        echo '<p>' . esc_html($texts['device']) . '</p>';
    }

    public function renderInpersonEmailInfo($order, $participant_id = null)
    {
        $context = $participant_id
            ? ctx()->withParticipant($participant_id)
            : ctx()->withOrder($order);

        if (!$context->hasInpersonEmailInfo()) {
            return;
        }

        $texts = $context->emailInpersonInfoTexts();
        echo '<p>' . wp_kses_post($texts['qr_at_venue']) . '</p>';
    }

    // Email detail rows are now rendered by FieldBuilder::getEmailDetailRows()
    // called directly from the email template (priority-sorted unified list).

    public function getInfoText($text)
    {
        $custom = $this->settings->get('email.info_text', '');
        if (empty($custom)) {
            return $text;
        }
        return strtr($custom, $this->infoTextPlaceholders());
    }

    public function getFooterInfoText($text)
    {
        // RegistrationContext::footerInfoText() already resolved this text for
        // the order's language. Re-reading the setting here would silently
        // downgrade it to the request language, so leave a resolved value alone.
        if ($text !== '' && $text !== null) {
            return $text;
        }

        $custom = Settings::getTranslatable('email.footer_info_text');
        if (empty($custom)) {
            return $text;
        }
        $contact_email = get_event_organization_contact_email();
        return strtr($custom, array_merge(
            $this->infoTextPlaceholders(),
            ['{contact_email}' => $contact_email]
        ));
    }

    /**
     * Placeholders supported in info_text / footer_info_text templates.
     * Locative variants fall back to nominative when not set.
     *
     * @return array<string,string>
     */
    private function infoTextPlaceholders()
    {
        return [
            '{event_name}'                  => get_event_name_with_year(),
            '{event_name_locative}'         => get_event_name_locative(),
            '{event_name_accusative}'       => get_event_name_accusative(),
            '{event_noun}'                  => get_event_noun(),
            '{event_noun_genitive}'         => get_event_noun_genitive(),
            '{event_date}'                  => get_event_dates_string(),
            '{event_time}'                  => get_event_time(),
            '{event_location}'              => get_event_location(),
            '{event_location_locative}'     => get_event_location('locative'),
            '{event_location_with_street}'  => get_event_location_with_street(),
            '{event_location_with_street_locative}' => get_event_location_with_street('locative'),
        ];
    }

    // =========================================================================
    // Checkout
    // =========================================================================

    public function isGdprEnabled($enabled)
    {
        $setting = $this->settings->get('checkout.enable_gdpr_consent');
        return $setting !== '' ? (bool) $setting : $enabled;
    }

    public function isTermsEnabled($enabled)
    {
        $setting = $this->settings->get('checkout.enable_terms_consent');
        return $setting !== '' ? (bool) $setting : $enabled;
    }

    public function getTermsUrl($url)
    {
        return $this->settings->get('checkout.terms_url', '') ?: $url;
    }

    public function getGdprUrl($url)
    {
        return $this->settings->get('checkout.gdpr_url', '') ?: $url;
    }

    public function isSkipEmailDuplicate($skip, $product_id = 0)
    {
        // Per-product override
        if ($product_id) {
            $product_skip = get_post_meta($product_id, '_alttag_skip_email_duplicate_check', true);
            if ($product_skip === '1' || $product_skip === 'yes') {
                return true;
            }
        }

        $setting = $this->settings->get('checkout.skip_email_duplicate_check');
        return $setting !== '' ? (bool) $setting : $skip;
    }

    public function isMultiDayDuplicateValidationEnabled($enabled)
    {
        $setting = $this->settings->get('participants.enable_multi_day_duplicate_validation');
        if ($setting !== '') {
            return (bool) $setting;
        }

        return is_event_multi_day() ? true : $enabled;
    }

    public function displayInvoiceDownloadLink($display)
    {
        // Auto-hide if no invoicing plugin is active
        if (!RegistrationContext::current()->hasInvoicing()) {
            return false;
        }

        $setting = $this->settings->get('checkout.display_invoice_download_link');
        return $setting !== '' ? (bool) $setting : $display;
    }

    public function isExtendedParticipantFeaturesEnabled($enabled)
    {
        $setting = $this->settings->get('modules.enable_extended_participant_features');
        return $setting !== '' ? (bool) $setting : $enabled;
    }

    public function isMultiDayParticipantFeaturesEnabled($enabled)
    {
        $setting = $this->settings->get('participants.enable_multi_day_participant_features');
        if ($setting !== '') {
            return (bool) $setting;
        }

        return is_event_multi_day() ? true : $enabled;
    }

    // =========================================================================
    // Livestream
    // =========================================================================

    public function isLivestreamEnabled($enabled)
    {
        $setting = $this->settings->get('modules.enable_livestream');
        return $setting !== '' ? (bool) $setting : $enabled;
    }

    public function getStreamUrl($url)
    {
        return $this->settings->get('livestream.stream_url', '') ?: $url;
    }

    public function getStreamLinkText($text)
    {
        return $this->settings->get('livestream.stream_link_text', '') ?: $text;
    }

    public function getWebhookUrl($url)
    {
        return $this->settings->get('livestream.webhook_url', '') ?: $url;
    }

    // =========================================================================
    // Livestream Detection (using core helpers)
    // =========================================================================

    public function isLivestreamUser($is_livestream, $order_data)
    {
        // A participant built from one line item is livestream iff THAT item's
        // product is. Falling back to the order-wide check here would flag the
        // in-person participant of a mixed cart as a livestream user and grant
        // it Riverstream access it never paid for.
        if (!empty($order_data['product_id'])) {
            return product_is_livestream((int) $order_data['product_id']);
        }
        if (!isset($order_data['order_id'])) {
            return $is_livestream;
        }
        $order = wc_get_order($order_data['order_id']);
        if (!$order) {
            return $is_livestream;
        }
        return has_online_attendance_product($order);
    }

    public function hasOnlineProduct($has_online, $order)
    {
        return has_online_attendance_product($order);
    }

    public function cartHasOnlineProduct($has_online)
    {
        return cart_contains_online_attendance_product();
    }

    public function orderHasOnlyLivestream($only_livestream, $order)
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && !product_is_livestream($product->get_id())) {
                return false;
            }
        }
        // If we got here and there are items, they're all livestream
        return count($order->get_items()) > 0;
    }

    public function isParticipantLivestream($is_livestream, $participant_id)
    {
        if (!$participant_id) {
            return false;
        }
        return ctx()->withParticipant($participant_id)->isLivestreamParticipant();
    }

    /**
     * Echo extra CSS into the email <style> block.
     */
    public function outputEmailCustomCss($order)
    {
        $css = Settings::getValue('email.custom_css');
        if ($css === '') {
            return;
        }

        echo "\n" . $css . "\n";
    }

    // =========================================================================
    // Non-membership email + receipt overrides driven by Settings templates
    // =========================================================================

    /**
     * Order-processing email title for non-membership orders, using
     * Settings.email.{inperson|livestream}_email_title_{paid|unpaid}.
     */
    public function maybeNonMembershipEmailTitle($title, $order, $participant_id = null)
    {
        if (!$order instanceof \WC_Order || has_membership_product($order)) {
            return $title;
        }

        $is_livestream = order_has_only_livestream_product($order);
        $status_part = $order->get_status() === 'completed' ? 'paid' : 'unpaid';
        $target = $is_livestream ? 'livestream' : 'inperson';
        $key = sprintf('email.%s_email_title_%s', $target, $status_part);

        $template = Settings::getValue($key);
        if ($template === '') {
            return $title;
        }

        return $this->applyEventPlaceholders($template, $order, $participant_id);
    }

    /**
     * Email event-details line for non-membership orders.
     */
    public function maybeNonMembershipEventDetails($content, $order, $participant_id = null)
    {
        if (!$order instanceof \WC_Order || has_membership_product($order)) {
            return $content;
        }

        $is_livestream = order_has_only_livestream_product($order);
        $key = $is_livestream
            ? 'email.livestream_event_details'
            : 'email.inperson_event_details';

        $template = Settings::getValue($key);
        if ($template === '') {
            return $content;
        }

        return $this->applyEventPlaceholders($template, $order, $participant_id);
    }

    /**
     * Rewrite participant manual-email subject for livestream users — replaces
     * the configured paid/unpaid in-person prefixes with livestream variants.
     */
    public function maybeLivestreamSubjectReplace($subject, $participant, $participant_id)
    {
        if (!$participant_id) {
            return $subject;
        }

        $is_livestream = (bool) get_post_meta($participant_id, 'is_livestream_user', true);
        if (!$is_livestream) {
            return $subject;
        }

        // Replace both paid and unpaid in-person prefixes with the livestream titles.
        $pairs = [
            'email.inperson_email_title_paid' => 'email.livestream_email_title_paid',
            'email.inperson_email_title_unpaid' => 'email.livestream_email_title_unpaid',
        ];

        foreach ($pairs as $from_key => $to_key) {
            $from = strip_tags(str_replace('<br>', ' ', Settings::getValue($from_key)));
            $to = strip_tags(str_replace('<br>', ' ', Settings::getValue($to_key)));
            // Strip placeholder portions from "from" so we match only the prefix.
            $from_prefix = trim(preg_replace('/\s*\{[^}]+\}.*/', '', $from));
            $to_prefix = trim(preg_replace('/\s*\{[^}]+\}.*/', '', $to));
            if ($from_prefix === '' || $to_prefix === '') {
                continue;
            }
            if (strpos($subject, $from_prefix) !== false) {
                $subject = str_replace($from_prefix, $to_prefix, $subject);
            }
        }

        return $subject;
    }

    /**
     * Replace event placeholders in a template string.
     *
     * Establishes the order/participant as the current RegistrationContext
     * for the duration of the substitution so each email reflects ITS
     * product's _event_* meta (not whatever happens to be in the cart at
     * filter time, which is usually nothing during background sends).
     */
    private function applyEventPlaceholders($template, $order = null, $participant_id = null)
    {
        $hasContext = false;
        if ($participant_id) {
            RegistrationContext::forParticipant($participant_id);
            $hasContext = true;
        } elseif ($order instanceof \WC_Order) {
            RegistrationContext::forOrder($order);
            $hasContext = true;
        }

        try {
            $context = RegistrationContext::current();
            return strtr($template, [
                '{event_name}'              => get_event_name(),
                '{event_name_locative}'     => get_event_name_locative(),
                '{event_name_accusative}'   => get_event_name_accusative(),
                '{event_noun}'              => get_event_noun(),
                '{event_noun_genitive}'     => get_event_noun_genitive(),
                '{event_dates}'             => get_event_dates_string(),
                '{event_time}'              => get_event_time(),
                '{event_location}'          => $context->eventLocationWithMap('locative'),
                '{event_location_locative}' => $context->eventLocationWithMap('locative'),
                '{map_url}'                 => $context->eventMapUrl(),
            ]);
        } finally {
            if ($hasContext) {
                RegistrationContext::reset();
            }
        }
    }
}
