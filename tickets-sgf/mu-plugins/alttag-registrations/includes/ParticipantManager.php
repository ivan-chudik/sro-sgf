<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

use Alttag\Registrations\Participant\Manager;
use Alttag\Registrations\Participant\PostType;
use Alttag\Registrations\Participant\AdminUI;
use Alttag\Registrations\Participant\Export;
use Alttag\Registrations\Participant\Email;
use Alttag\Registrations\Participant\Ticket;
use Alttag\Registrations\Participant\Import;

/**
 * Main participant manager class that coordinates all participant-related functionality
 */
class ParticipantManager
{
    public $verificationManager;
    private $manager;
    private $postType;
    private $adminUI;
    private $export;
    private $email;
    private $ticket;
    private $import;

    public function __construct()
    {
        $enable = apply_filters('alttag_registrations_enable_participants', true);

        if (!$enable) {
            return;
        }

        // Initialize the core manager class
        $this->manager = new Manager();

        // Initialize specialized classes
        $this->postType = new PostType($this->manager);
        $this->ticket = new Ticket($this->manager);
        $this->email = new Email($this->manager);
        $this->export = new Export($this->manager);
        $this->import = new Import($this->manager, $this->email);

        // AdminUI needs references to ticket and email
        $this->adminUI = new AdminUI($this->manager, $this->ticket, $this->email);

        // Add actions for bulk operations
        add_action('admin_post_resend_email_with_ticket_and_invoice', [$this, 'handleResendEmailWithTicketAndInvoice']);
        add_action('admin_post_regenerate_ticket', [$this, 'handleRegenerateTicket']);
        
        add_action('admin_action_email_participants', [$this, 'setupParticipantsForEmail']);
    }

    /**
     * Set the verification manager
     *
     * @param VerificationManager $verificationManager The verification manager
     */
    public function setVerificationManager($verificationManager)
    {
        $this->verificationManager = $verificationManager;
        $this->manager->setVerificationManager($verificationManager);
        $this->ticket->setVerificationManager($verificationManager);
    }

    /**
     * Get the verification manager
     *
     * @return VerificationManager The verification manager
     */
    public function getVerificationManger()
    {
        return $this->verificationManager;
    }

    /**
     * Handle resending email with ticket and invoice
     */
    public function handleResendEmailWithTicketAndInvoice()
    {
        $this->email->handleResendEmailWithTicketAndInvoice();
    }

    /**
     * Handle regenerating ticket
     */
    public function handleRegenerateTicket()
    {
        $this->ticket->handleRegenerateTicket();
    }

    /**
     * Setup participants for email
     */
    public function setupParticipantsForEmail()
    {
        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to send emails to participants', 'alttag-registrations'));
        }

        // Get participant IDs
        $participant_ids = isset($_GET['post']) ? (array) $_GET['post'] : [];
        if (empty($participant_ids)) {
            wp_die(__('No participants selected', 'alttag-registrations'));
        }

        // Store participant IDs in transient for use in email page (10 minutes expiration)
        set_transient('alttag_email_participants', $participant_ids, 600);

        // Redirect to email page
        wp_redirect(admin_url('edit.php?post_type=participant&page=alttag-email'));
        exit;
    }

    /**
     * Get participant by order ID
     *
     * @param int $order_id The order ID
     * @return WP_Post|null The participant post or null if not found
     */
    public function getParticipantByOrderId($order_id)
    {
        return $this->manager->getParticipantByOrderId($order_id);
    }

    /**
     * Get participant by order LINE ITEM ID
     *
     * @param int $order_id The order ID
     * @param int $order_item_id The order line item ID
     * @return WP_Post|array The participant post or [] if not found
     */
    public function getParticipantByOrderItemId($order_id, $order_item_id)
    {
        return $this->manager->getParticipantByOrderItemId($order_id, $order_item_id);
    }

    /**
     * Get participant by variable symbol
     *
     * @param string $variable_symbol The variable symbol
     * @return WP_Post|null The participant post or null if not found
     */
    public function getParticipantByVariableSymbol($variable_symbol)
    {
        return $this->manager->getParticipantByVariableSymbol($variable_symbol);
    }

    /**
     * Create a new participant
     *
     * @param array $order_data The order data
     * @return int|false The participant ID or false on failure
     */
    public function createParticipant($order_data)
    {
        return $this->manager->createParticipant($order_data);
    }

    /**
     * Update a participant
     *
     * @param int $post_id The participant ID
     * @param array $order_data The order data
     * @return bool True on success, false on failure
     */
    public function updateParticipant($post_id, $order_data)
    {
        return $this->manager->updateParticipant($post_id, $order_data);
    }

    /**
     * Get participant details
     *
     * @param int $participant_id The participant ID
     * @return array|false The participant details or false on failure
     */
    public function getParticipantDetails($participant_id)
    {
        return $this->manager->getParticipantDetails($participant_id);
    }

    /**
     * Resend email with ticket and invoice to multiple participants
     * 
     * @param array $participant_ids Array of participant IDs
     * @return array Array with counts of success and failure
     */
    public function resendEmailWithTicketAndInvoice($participant_ids)
    {
        return $this->email->resendEmailWithTicketAndInvoice($participant_ids);
    }
}
