<?php

namespace Alttag\Registrations\Participant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles ticket functionality for participants
 */
class Ticket
{
    private $manager;
    private $verificationManager;

    public function __construct(Manager $manager)
    {
        $enable = apply_filters('alttag_registrations_enable_participants', true);

        if (!$enable) {
            return;
        }

        $this->manager = $manager;

        // Add action for regenerating ticket
        add_action('admin_post_regenerate_ticket', [$this, 'handleRegenerateTicket']);
    }

    /**
     * Set the verification manager
     *
     * @param object $verificationManager The verification manager
     */
    public function setVerificationManager($verificationManager)
    {
        $this->verificationManager = $verificationManager;
    }

    /**
     * Handle regenerating ticket
     */
    public function handleRegenerateTicket()
    {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'regenerate_ticket_' . $_GET['participant_id'])) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to perform this action', 'alttag-registrations'));
        }

        // Get participant ID
        $participant_id = isset($_GET['participant_id']) ? intval($_GET['participant_id']) : 0;
        if (!$participant_id) {
            wp_die(__('Invalid request', 'alttag-registrations'));
        }

        // Regenerate ticket
        $result = $this->regenerateTicket($participant_id);

        // Always redirect back to the participant detail page
        $redirect_url = add_query_arg(
            [
                'ticket_regenerated' => $result ? '1' : '0',
                'participant_id' => $participant_id,
            ],
            admin_url('post.php?post=' . $participant_id . '&action=edit')
        );

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Regenerate ticket for a participant
     *
     * @param int $participant_id The participant ID
     * @return bool True if the ticket was regenerated successfully, false otherwise
     */
    public function regenerateTicket($participant_id)
    {
        if (!$this->verificationManager) {
            return false;
        }

        $participant = get_post($participant_id);
        if (!$participant || $participant->post_type !== 'participant') {
            return false;
        }

        // Generate and save ticket
        $result = $this->verificationManager->generateAndSaveTicket($participant_id);

        if ($result) {
            $state = \Alttag\Registrations\ParticipantState::get($participant_id);
            if ($state) {
                $state->addToHistory(__('Ticket regenerated', 'alttag-registrations'));
            }
        }

        return $result;
    }
}
