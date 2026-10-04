<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles ticket designer and preview functionality
 */
class TicketDesigner
{
    private $verificationManager;
    private $participantManager;
    private $ticketManager;

    public function registerHooks()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_get_ticket_preview', [$this, 'ajaxGetTicketPreview']);
        add_action('admin_post_preview_ticket_pdf', [$this, 'handlePreviewPdf']);
    }

    /**
     * Set the verification manager
     */
    public function setVerificationManager($verificationManager)
    {
        $this->verificationManager = $verificationManager;
    }

    /**
     * Set the participant manager
     */
    public function setParticipantManager($participantManager)
    {
        $this->participantManager = $participantManager;
    }

    /**
     * Set the ticket generator
     */
    public function setTicketManager($ticketManager)
    {
        $this->ticketManager = $ticketManager;
    }

    /**
     * Add admin menu
     */
    public function addAdminMenu()
    {
        add_submenu_page(
            'edit.php?post_type=participant',
            __('Ticket Preview', 'alttag-registrations'),
            __('Ticket Preview', 'alttag-registrations'),
            'manage_options',
            'ticket-preview',
            [$this, 'renderPreviewPage']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets($hook)
    {
        if ($hook !== 'participant_page_ticket-preview') {
            return;
        }

        wp_enqueue_style(
            'ticket-designer-style',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/ticket-designer.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'ticket-designer-script',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/ticket-designer.js',
            ['jquery'],
            '1.0.0',
            true
        );

        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';

        wp_localize_script('ticket-designer-script', 'ticketDesigner', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ticket_preview_nonce'),
            'logo_url' => $logo_url,
        ]);
    }

    /**
     * Render the preview page
     */
    public function renderPreviewPage()
    {
        // Get all participants
        $participants = get_posts([
            'post_type' => 'participant',
            'posts_per_page' => -1,
            'orderby' => 'post_date',
            'order' => 'DESC',
        ]);

        include ALTTAG_REGISTRATIONS_PATH . '/templates/admin/ticket-preview.php';
    }

    /**
     * AJAX handler to get ticket preview
     */
    public function ajaxGetTicketPreview()
    {
        check_ajax_referer('ticket_preview_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'alttag-registrations')]);
        }

        $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;

        if (!$participant_id) {
            wp_send_json_error(['message' => __('Invalid participant ID', 'alttag-registrations')]);
        }

        $participant = get_post($participant_id);
        if (!$participant || $participant->post_type !== 'participant') {
            wp_send_json_error(['message' => __('Participant not found', 'alttag-registrations')]);
        }

        // Generate preview PDF URL with nonce
        $preview_url = add_query_arg([
            'action' => 'preview_ticket_pdf',
            'participant_id' => $participant_id,
            '_wpnonce' => wp_create_nonce('preview_ticket_' . $participant_id),
        ], admin_url('admin-post.php'));

        // Get participant info for display
        $data = $this->verificationManager->formatCustomerData($participant_id);
        $participant_info = [
            'name' => $data['first_name'] . ' ' . $data['last_name'],
            'email' => $data['email'],
            'company' => $data['company_name'],
        ];

        wp_send_json_success([
            'pdf_url' => $preview_url,
            'participant_info' => $participant_info,
        ]);
    }

    /**
     * Handle preview PDF generation
     */
    public function handlePreviewPdf()
    {
        // Verify nonce
        $participant_id = isset($_GET['participant_id']) ? intval($_GET['participant_id']) : 0;

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'preview_ticket_' . $participant_id)) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'alttag-registrations'));
        }

        if (!$participant_id) {
            wp_die(__('Invalid participant ID', 'alttag-registrations'));
        }

        $participant = get_post($participant_id);
        if (!$participant || $participant->post_type !== 'participant') {
            wp_die(__('Participant not found', 'alttag-registrations'));
        }

        // Check if this is preview mode with custom settings
        $preview_mode = isset($_GET['preview_mode']) && $_GET['preview_mode'] == 1;

        // If preview mode, set the preview language to override participant's language
        if ($preview_mode && isset($_GET['preview_lang']) && !empty($_GET['preview_lang'])) {
            $GLOBALS['ticket_preview_language'] = sanitize_text_field($_GET['preview_lang']);
        }

        // If preview mode with product context, set product_id for design resolution
        $preview_product_id = 0;
        if ($preview_mode && isset($_GET['preview_product_id']) && !empty($_GET['preview_product_id'])) {
            $preview_product_id = intval($_GET['preview_product_id']);
            $GLOBALS['current_ticket_product_id'] = $preview_product_id;
        }

        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        ticket_designer_debug_log('designer_preview.handle_pdf', [
            'participant_id' => (int) $participant_id,
            'preview_mode' => (bool) $preview_mode,
            'preview_lang' => $GLOBALS['ticket_preview_language'] ?? '',
            'preview_product_id' => (int) $preview_product_id,
            'participant_product_id' => $state ? (int) $state->getMeta('product_id') : 0,
            'participant_language' => $state ? $state->language() : '',
            'variable_symbol' => $state ? (string) $state->variable_symbol : '',
        ]);

        // Generate the preview PDF
        $pdf_content = $this->generatePreviewPdf($participant_id, $preview_mode);

        if (!$pdf_content) {
            wp_die(__('Could not generate ticket preview', 'alttag-registrations'));
        }

        // Output the PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="ticket-preview.pdf"');
        header('Content-Length: ' . strlen($pdf_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $pdf_content;
        exit;
    }

    /**
     * Generate preview PDF for a participant
     * This uses the exact same PDF generation as the actual tickets
     * but outputs to string instead of saving to file
     *
     * @param int $participant_id The participant ID
     * @param bool $preview_mode If true, use custom settings from transient
     */
    private function generatePreviewPdf($participant_id, $preview_mode = false)
    {
        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        $variable_symbol = $state ? $state->variable_symbol : '';
        if (empty($variable_symbol)) {
            return false;
        }

        // If in preview mode, temporarily set custom settings
        if ($preview_mode) {
            $custom_settings = get_transient('ticket_design_preview_' . get_current_user_id());
            if ($custom_settings) {
                // Store custom settings temporarily in a global variable
                // so pdf.php can access them
                $GLOBALS['ticket_design_preview_settings'] = $custom_settings;
                ticket_designer_debug_log('designer_preview.transient_loaded', [
                    'participant_id' => (int) $participant_id,
                    'summary' => ticket_designer_debug_settings_summary($custom_settings),
                ]);
            }
        }

        // Delegate to TicketManager to generate PDF as string
        $pdf_content = $this->ticketManager->generateTicketString($participant_id, $variable_symbol);

        // Clean up global variables
        if (isset($GLOBALS['ticket_design_preview_settings'])) {
            unset($GLOBALS['ticket_design_preview_settings']);
        }
        if (isset($GLOBALS['ticket_preview_language'])) {
            unset($GLOBALS['ticket_preview_language']);
        }
        if (isset($GLOBALS['current_ticket_product_id'])) {
            unset($GLOBALS['current_ticket_product_id']);
        }

        return $pdf_content;
    }
}
