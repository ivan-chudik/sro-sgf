<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles language switching for emails and tickets
 * Only active when Polylang is installed
 */
class LanguageManager
{
    /**
     * Track if language has been switched for email
     */
    private static $email_language_switched = false;

    /**
     * Track if language has been switched for ticket
     */
    private static $ticket_language_switched = false;

    public function registerHooks()
    {
        // Save language to participant - works even without Polylang (uses default)
        $this->initParticipantLanguageSaving();

        // Defer Polylang-dependent initialization until plugins are loaded
        // This is needed because MU-plugins load before regular plugins like Polylang
        add_action('plugins_loaded', [$this, 'initPolylangDependentFeatures'], 20);
    }

    /**
     * Initialize features that depend on Polylang being loaded
     */
    public function initPolylangDependentFeatures()
    {
        if (!function_exists('pll_current_language')) {
            return;
        }

        $this->initEmailLanguageSwitching();
        $this->initTicketLanguageSwitching();
    }

    /**
     * Initialize participant language saving
     */
    private function initParticipantLanguageSaving()
    {
        add_filter('alttag_registrations_participant_create_data', [$this, 'saveLanguageToParticipant'], 10, 2);
    }

    /**
     * Save language to participant when creating
     *
     * Priority: 1. Order language, 2. Current Polylang language, 3. Default language
     */
    public function saveLanguageToParticipant($order_data, $participant_post_id)
    {
        $order_id = $order_data['order_id'] ?? null;

        // Try to get language from order first
        if ($order_id) {
            $language = ctx()->withOrder($order_id)->orderLanguage();
        } else {
            // Fallback to current Polylang language or default
            $language = ctx()->currentLanguage();
        }

        $order_data['language'] = $language;

        return $order_data;
    }

    /**
     * Initialize email language switching hooks
     */
    private function initEmailLanguageSwitching()
    {
        // Switch language before email content is generated
        add_filter('alttag_registrations_email_title', [$this, 'switchToOrderLanguageForEmail'], 1, 2);
        add_filter('alttag_registrations_email_subject', [$this, 'switchToParticipantLanguageForEmail'], 1, 3);

        // Restore language after email is sent
        add_action('wp_mail_succeeded', [$this, 'restoreLanguageAfterEmail']);
        add_action('wp_mail_failed', [$this, 'restoreLanguageAfterEmail']);

        // Switch language before order/receipt emails
        add_action('alttag_registrations_before_order_email', [$this, 'switchToOrderLanguageBeforeEmail']);
        add_action('alttag_registrations_before_receipt_email', [$this, 'switchToOrderLanguageBeforeEmail']);

        // Restore language after order/receipt emails
        add_action('alttag_registrations_after_order_email', [$this, 'restoreLanguageAfterOrderEmail']);
        add_action('alttag_registrations_after_receipt_email', [$this, 'restoreLanguageAfterOrderEmail']);
    }

    /**
     * Initialize ticket language switching hooks
     */
    private function initTicketLanguageSwitching()
    {
        add_action('alttag_registrations_before_ticket_generation', [$this, 'switchLanguageBeforeTicket'], 5);
        add_action('alttag_registrations_after_ticket_generation', [$this, 'restoreLanguageAfterTicket']);
    }

    /**
     * Switch to order's language for email
     */
    public function switchToOrderLanguageForEmail($title, $order)
    {
        if (self::$email_language_switched) {
            return $title;
        }

        $language = ctx()->withOrder($order)->orderLanguage();
        $this->switchToLanguage($language);
        self::$email_language_switched = true;

        return $title;
    }

    /**
     * Switch to participant's language for email
     */
    public function switchToParticipantLanguageForEmail($subject, $participant, $participant_id)
    {
        if (self::$email_language_switched) {
            return $subject;
        }

        $language = ctx()->withParticipant($participant_id)->participantLanguage();
        $this->switchToLanguage($language);
        self::$email_language_switched = true;

        return $subject;
    }

    /**
     * Switch to order's language before email
     */
    public function switchToOrderLanguageBeforeEmail($order)
    {
        if (self::$email_language_switched) {
            return;
        }

        $language = ctx()->withOrder($order)->orderLanguage();
        $this->switchToLanguage($language);
        self::$email_language_switched = true;
    }

    /**
     * Restore language after email is sent
     */
    public function restoreLanguageAfterEmail($mail_data = null)
    {
        if (!self::$email_language_switched) {
            return;
        }

        $this->restoreOriginalLanguage();
        self::$email_language_switched = false;
    }

    /**
     * Restore language after order email
     */
    public function restoreLanguageAfterOrderEmail($order)
    {
        if (!self::$email_language_switched) {
            return;
        }

        $this->restoreOriginalLanguage();
        self::$email_language_switched = false;
    }

    /**
     * Switch language before ticket generation
     */
    public function switchLanguageBeforeTicket($participant_id)
    {
        if (self::$ticket_language_switched) {
            return;
        }

        // Check for preview language override
        if (isset($GLOBALS['ticket_preview_language']) && !empty($GLOBALS['ticket_preview_language'])) {
            $language = $GLOBALS['ticket_preview_language'];
        } else {
            $language = ctx()->withParticipant($participant_id)->participantLanguage();
        }

        $this->switchToLanguage($language);
        self::$ticket_language_switched = true;
    }

    /**
     * Restore language after ticket generation
     */
    public function restoreLanguageAfterTicket($participant_id)
    {
        if (!self::$ticket_language_switched) {
            return;
        }

        $this->restoreOriginalLanguage();
        self::$ticket_language_switched = false;
    }

    /**
     * Switch to specified language
     */
    private function switchToLanguage($language)
    {
        $locale = get_locale_from_lang($language);

        switch_language($language);

        unload_textdomain('alttag-registrations');
        load_textdomains_for_locale($locale);
    }

    /**
     * Restore original language
     */
    private function restoreOriginalLanguage()
    {
        restore_language();

        $original_locale = get_locale();
        unload_textdomain('alttag-registrations');
        load_textdomains_for_locale($original_locale);
    }
}
