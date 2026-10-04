<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class TemplateHandler
{
    public $verificationManager;

    public function getVerificationManger()
    {
        return $this->verificationManager;
    }
    
    public function setVerificationManager($verificationManager)
    {
        $this->verificationManager = $verificationManager;
    }

    public function init()
    {
        add_action('init', [$this, 'addRewriteRules']);
        add_filter('query_vars', [$this, 'addQueryVars']);
        add_action('template_redirect', [$this, 'handleTemplateRedirect']);

        // Long-lived "remember me" cookie for staff (edit_posts) so the verify
        // page on a phone doesn't force re-login every two weeks.
        add_filter('auth_cookie_expiration', [$this, 'extendStaffAuthCookie'], 10, 3);
    }

    /**
     * Bump the remember-me cookie expiration to one year for users who can
     * actually use the verify page. Regular customers keep WP defaults.
     */
    public function extendStaffAuthCookie($expiration, $user_id, $remember)
    {
        if (!$remember) {
            return $expiration;
        }
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return $expiration;
        }
        if (user_can($user, 'edit_posts')) {
            return YEAR_IN_SECONDS;
        }
        return $expiration;
    }

    /**
     * Sliding expiration for the staff auth cookie. Re-issues the cookie with
     * the SAME session token (so existing nonces stay valid across the same
     * browsing session) but updates the server-side session record's
     * expiration. Skips the work when the session already has plenty of life
     * left, to avoid hammering the user_meta on every page load.
     */
    public function refreshStaffAuthCookie($user_id)
    {
        if (!user_can($user_id, 'edit_posts')) {
            return;
        }

        $token = wp_get_session_token();
        if (!$token) {
            return;
        }

        $manager = \WP_Session_Tokens::get_instance($user_id);
        $session = $manager->get($token);
        if (!$session) {
            return;
        }

        $target = time() + YEAR_IN_SECONDS;
        // No-op if we already extended this session recently.
        if (!empty($session['expiration']) && $session['expiration'] >= $target - 30 * DAY_IN_SECONDS) {
            return;
        }

        $session['expiration'] = $target;
        $manager->update($token, $session);

        // Reuse $token so wp_create_nonce / wp_verify_nonce keep matching across
        // requests — without this, the next form submission would fail the
        // nonce check for any markup rendered with the previous token.
        wp_set_auth_cookie($user_id, true, '', $token);
    }

    public function addRewriteRules()
    {
        add_rewrite_rule(
            'verify/([^/]+)/?$',
            'index.php?verify=$matches[1]',
            'top'
        );
    }

    public function addQueryVars($query_vars)
    {
        $query_vars[] = 'verify';
        return $query_vars;
    }

    public function handleTemplateRedirect()
    {
        $verify = get_query_var('verify');
        $redirect_to = get_query_var('redirect_to');

        if ($verify) {
            if (!is_user_logged_in()) {
                // Manual redirect to the login page WITHOUT force_reauth so that
                // any still-valid cookie (e.g. partially trimmed by Safari ITP)
                // can re-authenticate cleanly instead of being wiped by wp-login
                // when reauth=1 is set.
                nocache_headers();
                $current_url = (is_ssl() ? 'https' : 'http') . '://'
                    . ($_SERVER['HTTP_HOST'] ?? '')
                    . ($_SERVER['REQUEST_URI'] ?? '');
                wp_safe_redirect(wp_login_url($current_url, false));
                exit;
            }

            // Set locale for verification page if multilingual
            if (\Alttag\Registrations\RegistrationContext::current()->hasPolylang()) {
                $this->setVerificationPageLocale();
            }

            // Handle POST first so it sees the same session token (and thus
            // the same nonce hashes) that rendered the submitted form.
            $this->handleFormSubmission();

            // Sliding expiration on GET only — issuing a new session before
            // form-render means the markup baked into the nonces match what
            // the browser will submit later. We deliberately skip this on
            // POST because handleFormSubmission already redirected away.
            $this->refreshStaffAuthCookie(get_current_user_id());

            $content = $this->verificationManager->handleVerification($verify);

            if ($content !== false) {
                echo $content;
            } else {
                wp_die("Neplatný verifikačný kód.", "Chyba overenia");
            }
            exit;
        }
    }

    private function handleFormSubmission()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registration_nonce']) && wp_verify_nonce($_POST['registration_nonce'], 'registration_action')) {
            $variable_symbol = sanitize_text_field($_POST['variable_symbol']);
            $action = sanitize_text_field($_POST['action'] ?? '');

            if ($action === 'register') {
                $this->verificationManager->markAsRegistered($variable_symbol);
            } elseif ($action === 'cancel') {
                $this->verificationManager->cancelRegistration($variable_symbol);
            } elseif ($action === 'register_all') {
                $this->verificationManager->markAllAsRegistered($variable_symbol);
            } elseif ($action === 'cancel_all') {
                $this->verificationManager->cancelAllRegistrations($variable_symbol);
            } elseif (strpos($action, 'checkout_person_') === 0) {
                $date = str_replace('checkout_person_', '', $action);
                $this->verificationManager->checkoutPersonForDate($variable_symbol, $date);
            } elseif (strpos($action, 'checkin_all_') === 0) {
                $date = str_replace('checkin_all_', '', $action);
                $this->verificationManager->checkinAllPersonsForDate($variable_symbol, $date);
            } elseif (strpos($action, 'checkout_all_') === 0) {
                $date = str_replace('checkout_all_', '', $action);
                $this->verificationManager->checkoutAllPersonsForDate($variable_symbol, $date);
            } elseif (strpos($action, 'register_date_') === 0) {
                $date = str_replace('register_date_', '', $action);
                $this->verificationManager->markAsRegisteredForDate($variable_symbol, $date);
            } elseif (strpos($action, 'cancel_date_') === 0) {
                $date = str_replace('cancel_date_', '', $action);
                $this->verificationManager->cancelRegistrationForDate($variable_symbol, $date);
            } else {
                // Allow custom actions via action hook for other plugins
                do_action('alttag_registrations_handle_custom_action', $variable_symbol, $action);
            }

            // Redirect to the same page to prevent form resubmission
            wp_redirect(home_url('verify/' . $variable_symbol));
            exit;
        }
    }

    /**
     * Set locale for verification page
     * Uses filterable language slug, defaults to site's default language
     */
    private function setVerificationPageLocale()
    {
        // Allow filtering the verification page language (default: get from Polylang default or 'sk')
        $default_lang = \Alttag\Registrations\RegistrationContext::current()->defaultLanguage();
        $language = apply_filters('alttag_registrations_verification_page_language', $default_lang);

        if (!empty($language)) {
            // Get Polylang language object
            $lang = \PLL()->model->get_language($language);
            if ($lang) {
                // Switch to the specified language
                \PLL()->curlang = $lang;

                // Update locale
                $locale = $lang->locale;
                add_filter('locale', function () use ($locale) {
                    return $locale;
                }, 100);

                // Reload textdomains with new locale
                unload_textdomain('alttag-registrations');
                load_muplugin_textdomain('alttag-registrations', 'alttag-registrations/languages');
            }
        }
    }
}
