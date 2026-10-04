<?php
/**
 * Ticket Preview Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap ticket-preview-page">
    <h1><?php _e('Ticket Preview', 'alttag-registrations'); ?></h1>

    <div class="ticket-preview-container">
        <div class="participant-selector-section">
            <h2><?php _e('Select Participant', 'alttag-registrations'); ?></h2>

            <div class="participant-selector-controls">
                <label for="participant-select">
                    <?php _e('Choose a participant to preview their ticket:', 'alttag-registrations'); ?>
                </label>

                <select id="participant-select" class="participant-select">
                    <option value=""><?php _e('-- Select Participant --', 'alttag-registrations'); ?></option>
                    <?php foreach ($participants as $participant):
                        $pState = \Alttag\Registrations\ParticipantState::get($participant->ID);
                        $first_name = $pState ? $pState->first_name : '';
                        $last_name = $pState ? $pState->last_name : '';
                        $email = $pState ? $pState->email : '';
                        $company = $pState ? $pState->company_name : '';
                        $variable_symbol = $pState ? $pState->variable_symbol : '';

                        $display_name = $first_name . ' ' . $last_name;
                        if ($company) {
                            $display_name .= ' (' . $company . ')';
                        }
                        if ($email) {
                            $display_name .= ' - ' . $email;
                        }
                        if ($variable_symbol) {
                            $display_name .= ' [' . $variable_symbol . ']';
                        }
                    ?>
                        <option value="<?php echo esc_attr($participant->ID); ?>">
                            <?php echo esc_html($display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="button" id="load-preview-btn" class="button button-primary">
                    <?php _e('Load Preview', 'alttag-registrations'); ?>
                </button>
            </div>

            <div id="preview-loading" class="preview-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <?php _e('Loading preview...', 'alttag-registrations'); ?>
            </div>

            <div id="preview-error" class="notice notice-error" style="display: none;">
                <p></p>
            </div>
        </div>

        <div id="ticket-preview-section" class="ticket-preview-section" style="display: none;">
            <h2><?php _e('Ticket Preview', 'alttag-registrations'); ?></h2>

            <div id="participant-info" class="participant-info"></div>

            <div id="ticket-preview" class="ticket-preview">
                <iframe
                    id="ticket-pdf-iframe"
                    class="ticket-pdf-iframe"
                    frameborder="0"
                    title="<?php esc_attr_e('Ticket Preview', 'alttag-registrations'); ?>">
                </iframe>
            </div>
        </div>
    </div>
</div>
