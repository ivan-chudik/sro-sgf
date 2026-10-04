<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Core;
use Alttag\Registrations\Selection\ParticipantTypeSelection;
use Alttag\Registrations\Selection\SelectionManager;

use function Alttag\Registrations\ctx;
use function Alttag\Registrations\get_participant_by_variable_symbol;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Participant Types module.
 *
 * Lets admins define participant categories per product (e.g. Dospelý / Dieťa / Senior),
 * each with its own price. At checkout the customer picks how many of each type to register.
 *
 * Product meta:
 *   _participant_types = [
 *     ['id' => 'adult', 'label' => 'Dospelý', 'price' => 5.0],
 *     ['id' => 'child', 'label' => 'Dieťa',   'price' => 0.0],
 *   ]
 *
 * Optional per-type multi-day tier pricing (combined with DaySelection):
 *   ['id' => 'adult', 'label' => 'Dospelý', 'price' => 4.0,
 *    'tiers' => [1 => 4.0, 2 => 6.0]]
 *
 * Per-product enable flag lives in _alttag_module_participant_types (handled by AbstractModule).
 */
class ParticipantTypeModule extends AbstractModule
{
    /** Export column keys. */
    private const EXPORT_TYPE_PREFIX = 'participant_type_count_';
    private const EXPORT_TOTAL = 'participant_types_total';
    private const EXPORT_VERIFIED = 'participant_types_verified';

    /** @var ParticipantTypeSelection */
    private $selection;

    public function getId(): string
    {
        return 'participant_types';
    }

    public function getName(): string
    {
        return __('Participant Types', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('Let customers pick how many participants of each type (Adult, Child, …) to register, with per-type pricing.', 'alttag-registrations');
    }

    public function getSettingsTab(): ?string
    {
        return 'modules';
    }

    public function hasProductToggle(): bool
    {
        return true;
    }

    public function registerHooks(): void
    {
        $this->selection = new ParticipantTypeSelection();

        // Register selection type so SelectionManager handles checkout pricing/display.
        add_action('alttag_registrations_register_selection_types', function () {
            SelectionManager::register($this->selection);
        });

        // Product admin
        add_filter('woocommerce_product_data_tabs', [$this, 'addProductTab']);
        add_action('woocommerce_product_data_panels', [$this, 'renderProductPanel']);
        add_action('woocommerce_process_product_meta', [$this, 'saveProductMeta']);

        // Meta field definitions (for admin/export)
        add_filter('alttag_registrations_meta_fields', [$this, 'registerMetaFields']);

        // Admin participant list — render per-type status inside existing registration_status column
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderAdminColumn'], 20, 3);
        add_action('add_meta_boxes_participant', [$this, 'addSummaryMetaBox']);

        // Priority 20: after MultiDayModule, whose person counts are corrected here.
        add_filter('alttag_registrations_export_fields', [$this, 'extendExportFields'], 20, 3);
        add_filter('alttag_registrations_export_cell_value', [$this, 'exportCellValue'], 20, 4);

        // Email
        add_action('alttag_registrations_email_after_custom_fields', [$this, 'renderEmailSummary'], 10, 2);

        // Thank-you page — render inline inside the SessionModule info box
        // when present; fall back to standalone block otherwise.
        add_action('alttag_registrations_thankyou_session_info_box', [$this, 'renderInsideSessionBox'], 10, 2);
        add_action('alttag_registrations_checkout_after_download_links', [$this, 'renderThankYouSummary'], 20);

        // Ticket
        add_filter('alttag_registrations_pdf_ticket_data', [$this, 'addTicketData'], 10, 3);
        add_filter('alttag_registrations_ticket_field_order_options', [$this, 'addTicketFieldOptions']);

        // Verification page
        add_filter('alttag_registrations_customer_data', [$this, 'populateVerificationData'], 10, 3);
        add_filter('alttag_registrations_verification_field_value', [$this, 'formatVerificationValue'], 10, 3);
        add_filter('alttag_registrations_verification_field_label', [$this, 'getVerificationLabel'], 10, 3);
        add_filter('alttag_registrations_verification_sections', [$this, 'addVerificationFields']);
        add_action('alttag_registrations_verification_after_section_content', [$this, 'renderVerificationTable'], 10, 3);
        add_filter('alttag_registrations_show_register_button', [$this, 'filterShowRegisterButton'], 10, 2);
        add_action('alttag_registrations_handle_custom_action', [$this, 'handleVerificationAction'], 10, 2);

        // Export
        add_filter('alttag_registrations_export_field_order', [$this, 'addExportFields']);
        add_filter('alttag_registrations_export_cell_value', [$this, 'formatExportValue'], 10, 4);
    }

    public function getSelection(): ParticipantTypeSelection
    {
        return $this->selection;
    }

    // =========================================================================
    // Product admin: tab + repeater
    // =========================================================================

    public function addProductTab($tabs)
    {
        $tabs['participant_types'] = [
            'label' => __('Participant Types', 'alttag-registrations'),
            'target' => 'participant_types_product_data',
            'class' => [],
            'priority' => 66,
        ];
        return $tabs;
    }

    public function renderProductPanel()
    {
        global $post;
        if (!$post) {
            return;
        }

        $types = get_post_meta($post->ID, '_participant_types', true);
        if (!is_array($types)) {
            $types = [];
        }
        $currency = get_woocommerce_currency_symbol();
        ?>
        <div id="participant_types_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field" style="padding: 0 20px;">
                    <span class="description">
                        <?php esc_html_e('Define participant categories and their prices. Customer picks how many of each at checkout. Enable this module on the "General" tab under "Participant Types".', 'alttag-registrations'); ?>
                    </span>
                </p>

                <div id="participant-types-rows" style="padding: 0 12px;">
                    <div style="display: flex; gap: 8px; padding: 5px 8px; align-items: center; font-size: 11px; color: #777; text-transform: uppercase;">
                        <span style="width: 110px;"><?php esc_html_e('ID', 'alttag-registrations'); ?></span>
                        <span style="width: 200px;"><?php esc_html_e('Label', 'alttag-registrations'); ?></span>
                        <span style="width: 100px;"><?php esc_html_e('Price', 'alttag-registrations'); ?></span>
                        <span style="width: 70px;"><?php esc_html_e('Min', 'alttag-registrations'); ?></span>
                        <span style="width: 70px;"><?php esc_html_e('Max', 'alttag-registrations'); ?></span>
                        <span style="width: 160px;" title="<?php esc_attr_e('Per-type pricing tiers by selected day count. Format: 1:4.00, 2:6.00. Overrides Price when DaySelection is active.', 'alttag-registrations'); ?>"><?php esc_html_e('Tiers (days:price)', 'alttag-registrations'); ?></span>
                        <span></span>
                    </div>
                    <?php foreach ($types as $i => $row) : ?>
                    <div class="participant-type-admin-row" style="display: flex; gap: 8px; padding: 5px 8px; align-items: center;">
                        <input type="text" name="_participant_types[<?php echo (int) $i; ?>][id]"
                               value="<?php echo esc_attr($row['id'] ?? ''); ?>"
                               placeholder="adult" style="width: 110px;" />
                        <input type="text" name="_participant_types[<?php echo (int) $i; ?>][label]"
                               value="<?php echo esc_attr($row['label'] ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Adult', 'alttag-registrations'); ?>" style="width: 200px;" />
                        <input type="text" name="_participant_types[<?php echo (int) $i; ?>][price]"
                               value="<?php echo esc_attr($row['price'] ?? ''); ?>"
                               placeholder="0.00" style="width: 80px; text-align: right;" />
                        <span><?php echo esc_html($currency); ?></span>
                        <input type="number" name="_participant_types[<?php echo (int) $i; ?>][min]"
                               value="<?php echo esc_attr($row['min'] ?? ''); ?>"
                               placeholder="0" min="0" style="width: 60px; text-align: right;" />
                        <input type="number" name="_participant_types[<?php echo (int) $i; ?>][max]"
                               value="<?php echo esc_attr($row['max'] ?? ''); ?>"
                               placeholder="∞" min="0" style="width: 60px; text-align: right;" />
                        <input type="text" name="_participant_types[<?php echo (int) $i; ?>][tiers]"
                               value="<?php echo esc_attr($this->formatTiersForInput($row['tiers'] ?? [])); ?>"
                               placeholder="1:4.00, 2:6.00" style="width: 160px;" />
                        <button type="button" class="button participant-type-remove" style="color: #a00;">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p class="form-field" style="padding: 0 20px;">
                    <button type="button" class="button" id="participant-type-add-row">
                        + <?php esc_html_e('Add type', 'alttag-registrations'); ?>
                    </button>
                </p>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var $rows = $('#participant-types-rows');
            var idx = <?php echo count($types); ?>;
            var cur = '<?php echo esc_js($currency); ?>';

            $('#participant-type-add-row').on('click', function() {
                $rows.append(
                    '<div class="participant-type-admin-row" style="display:flex;gap:8px;padding:5px 8px;align-items:center;">'
                    + '<input type="text" name="_participant_types['+idx+'][id]" placeholder="adult" style="width:110px" />'
                    + '<input type="text" name="_participant_types['+idx+'][label]" placeholder="Adult" style="width:200px" />'
                    + '<input type="text" name="_participant_types['+idx+'][price]" placeholder="0.00" style="width:80px;text-align:right" />'
                    + '<span>'+cur+'</span>'
                    + '<input type="number" name="_participant_types['+idx+'][min]" placeholder="0" min="0" style="width:60px;text-align:right" />'
                    + '<input type="number" name="_participant_types['+idx+'][max]" placeholder="∞" min="0" style="width:60px;text-align:right" />'
                    + '<input type="text" name="_participant_types['+idx+'][tiers]" placeholder="1:4.00, 2:6.00" style="width:160px" />'
                    + '<button type="button" class="button participant-type-remove" style="color:#a00">&times;</button>'
                    + '</div>'
                );
                idx++;
            });

            $rows.on('click', '.participant-type-remove', function() {
                $(this).closest('.participant-type-admin-row').remove();
            });
        });
        </script>
        <?php
    }

    public function saveProductMeta($post_id)
    {
        if (!isset($_POST['_participant_types']) || !is_array($_POST['_participant_types'])) {
            delete_post_meta($post_id, '_participant_types');
            return;
        }

        $types = [];
        $seen_ids = [];
        foreach ($_POST['_participant_types'] as $row) {
            $id = isset($row['id']) ? sanitize_key(wp_unslash($row['id'])) : '';
            $label = isset($row['label']) ? sanitize_text_field(wp_unslash($row['label'])) : '';
            $price = isset($row['price']) ? (float) str_replace(',', '.', wp_unslash($row['price'])) : 0.0;
            $min_raw = isset($row['min']) ? wp_unslash($row['min']) : '';
            $max_raw = isset($row['max']) ? wp_unslash($row['max']) : '';
            $min = $min_raw === '' ? 0 : max(0, (int) $min_raw);
            $max = $max_raw === '' ? 0 : max(0, (int) $max_raw);
            $tiers = $this->parseTiersInput($row['tiers'] ?? '');

            if ($id === '' || $label === '' || isset($seen_ids[$id])) {
                continue;
            }
            $seen_ids[$id] = true;

            $entry = [
                'id' => $id,
                'label' => $label,
                'price' => $price,
                'min' => $min,
                'max' => $max,
            ];
            if (!empty($tiers)) {
                $entry['tiers'] = $tiers;
            }
            $types[] = $entry;
        }

        if (empty($types)) {
            delete_post_meta($post_id, '_participant_types');
        } else {
            update_post_meta($post_id, '_participant_types', $types);
        }
    }

    /**
     * Parse "1:4.00, 2:6.00" into [1 => 4.0, 2 => 6.0].
     */
    private function parseTiersInput($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $tiers = [];
        foreach (preg_split('/\s*,\s*/', wp_unslash($raw)) as $pair) {
            if (strpos($pair, ':') === false) {
                continue;
            }
            [$days, $price] = explode(':', $pair, 2);
            $days = (int) trim($days);
            $price = (float) str_replace(',', '.', trim($price));
            if ($days > 0) {
                $tiers[$days] = $price;
            }
        }
        ksort($tiers);
        return $tiers;
    }

    /**
     * Format [1 => 4.0, 2 => 6.0] back to "1:4.00, 2:6.00" for the input field.
     */
    private function formatTiersForInput($tiers): string
    {
        if (!is_array($tiers) || empty($tiers)) {
            return '';
        }
        $parts = [];
        foreach ($tiers as $days => $price) {
            $parts[] = ((int) $days) . ':' . number_format((float) $price, 2, '.', '');
        }
        return implode(', ', $parts);
    }

    // =========================================================================
    // Meta fields
    // =========================================================================

    public function registerMetaFields($fields)
    {
        $fields['selected_participant_types_data'] = [
            'label' => __('Participant Types', 'alttag-registrations'),
            'admin_label' => __('Participant Types', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        return $fields;
    }

    /**
     * Not hooked into the participant metabox on purpose.
     *
     * The raw meta is an array and the metabox renders fields as text inputs, so
     * it came out as an empty box (and an array-to-string notice). The read-only
     * summary box shows the same data with the check-in counts, which is what
     * anyone opening this screen actually wants.
     *
     * Kept for the verification page, which formats the value itself.
     */
    public function addMetaBoxFields($sections)
    {
        if (!isset($sections['personal']['fields'])) {
            $sections['personal']['fields'] = [];
        }
        $sections['personal']['fields'][] = 'selected_participant_types_data';
        return $sections;
    }

    // =========================================================================
    // Core helper: build per-type rows (label + totals + verified + status)
    // =========================================================================

    /**
     * Build a normalized list of per-type rows from a selected-types data map.
     * Every downstream renderer (admin column, verify table, email/ticket summary,
     * export, history labels) consumes this single shape.
     *
     * @param array<string,int|string> $selected   Map of type id → count.
     * @param int                      $product_id Product to resolve labels against.
     * @param array<string,int>        $verified   Map of type id → verified count.
     *
     * @return array<int,array{type_id:string,label:string,total:int,verified:int,color:string,marker:string}>
     */
    private function buildTypeRows(array $selected, int $product_id, array $verified = []): array
    {
        $labels = [];
        foreach ($this->selection->getTypes($product_id) as $t) {
            $labels[$t['id']] = $t['label'];
        }

        $rows = [];
        foreach ($selected as $type_id => $count) {
            $total = (int) $count;
            if ($total <= 0) {
                continue;
            }
            $done = isset($verified[$type_id]) ? (int) $verified[$type_id] : 0;
            $rows[] = [
                'type_id' => (string) $type_id,
                'label' => $labels[$type_id] ?? (string) $type_id,
                'total' => $total,
                'verified' => $done,
                'color' => $this->progressColor($done, $total),
                'marker' => $done >= $total ? 'OK' : '-',
            ];
        }
        return $rows;
    }

    private function progressColor(int $done, int $total): string
    {
        if ($done >= $total) {
            return 'green';
        }
        if ($done > 0) {
            return 'orange';
        }
        return '#999';
    }

    /**
     * Format a list of type rows as "2× Dospelý, 1× Dieťa".
     *
     * @param array<int,array{total:int,label:string}> $rows
     */
    private function formatRowsSummary(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['total'] . '× ' . $row['label'];
        }
        return implode(', ', $parts);
    }

    // =========================================================================
    // Summary helpers (participant + order)
    // =========================================================================

    private function formatTypesSummary($participant_id): string
    {
        $data = get_post_meta($participant_id, 'selected_participant_types_data', true);
        if (!is_array($data) || empty($data)) {
            return '';
        }

        $product_id = (int) get_post_meta($participant_id, 'product_id', true);
        return $this->formatRowsSummary($this->buildTypeRows($data, $product_id));
    }

    /**
     * Get summary for an order — works before participants are created (thank-you page).
     */
    private function formatOrderSummary(\WC_Order $order): string
    {
        foreach ($order->get_items() as $item) {
            $data = $item->get_meta('selected_participant_types_data');
            if (!is_array($data) || empty($data)) {
                continue;
            }
            $product_id = (int) ($item->get_variation_id() ?: $item->get_product_id());
            $summary = $this->formatRowsSummary($this->buildTypeRows($data, $product_id));
            if ($summary !== '') {
                return $summary;
            }
        }
        return '';
    }

    // =========================================================================
    // Admin list
    // =========================================================================

    /**
     * Render per-type check-in progress inside the existing "Registration Status" column
     * when the participant has participant types. Otherwise leaves the default content.
     */
    public function renderAdminColumn($content, $column, $post_id)
    {
        if ($column !== 'registration_status') {
            return $content;
        }

        $state = \Alttag\Registrations\ParticipantState::get($post_id);
        if (!$state) {
            return $content;
        }

        $selected = $state->getMeta('selected_participant_types_data');
        if (!is_array($selected) || empty($selected)) {
            return $content;
        }

        // Livestream participants don't do in-person check-in — keep default status badge
        if (ctx()->withParticipant($post_id)->isLivestreamParticipant()) {
            return $content;
        }

        $rows = $this->buildTypeRows(
            $selected,
            (int) $state->getMeta('product_id'),
            $this->getVerifiedTypesData($state)
        );
        if (empty($rows)) {
            return $content;
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = '<span style="color:' . $row['color'] . ';">' . $row['marker'] . ' '
                . esc_html($row['label']) . ' ' . $row['verified'] . '/' . $row['total'] . '</span>';
        }
        return implode('<br>', $lines);
    }

    // =========================================================================
    // Email
    // =========================================================================

    public function renderEmailSummary($order, $participant_id = null)
    {
        // With multi-participant checkout, the confirmation email already
        // lists every attendee individually (name + e-mail + job title).
        // The aggregate "Participants: 2× Number of seats" line then just
        // repeats the seat count in a jarring second style — skip it when
        // extras exist, matching the thank-you page behaviour.
        if ($order instanceof \WC_Order) {
            $extras = get_post_meta($order->get_id(), '_alttag_extra_participants', true);
            if (is_array($extras) && !empty($extras)) {
                return;
            }
        }

        // Prefer participant meta (post-create), fall back to order item meta (pre-create).
        $summary = $participant_id ? $this->formatTypesSummary($participant_id) : '';
        if ($summary === '' && $order instanceof \WC_Order) {
            $summary = $this->formatOrderSummary($order);
        }
        if ($summary === '') {
            return;
        }

        // Text color defaults to the primary color for BC with the original
        // light-background email template. Custom templates on dark
        // backgrounds (e.g. NBC) override `alttag_registrations_email_text_color`
        // separately so this line stays readable.
        $primary_color = apply_filters('alttag_registrations_email_primary_color', '#323232');
        $text_color    = apply_filters('alttag_registrations_email_text_color', $primary_color);
        $style = 'width:100%;box-sizing:border-box;margin:0 0 10px;color:' . $text_color
            . ';font-size:14px;font-family:Arial,Helvetica,sans-serif;line-height:1.6;';

        echo '<div style="' . esc_attr($style) . '">'
            . esc_html(sprintf(__('Participants: %s', 'alttag-registrations'), $summary))
            . '</div>';
    }

    /**
     * Thank-you page: show participant types after download links.
     */
    public function renderThankYouSummary($order)
    {
        // Skip when SessionModule already inserted us inside its info box.
        if (!empty($GLOBALS['_alttag_session_box_rendered'])) {
            return;
        }
        if (!($order instanceof \WC_Order)) {
            return;
        }

        // With multi-participant checkout, each attendee is materialised as
        // its own participant on the thank-you page (name + email list). The
        // aggregate "2× Number of seats" line then just repeats the seat
        // count without adding any new info — skip it in that case.
        $extras = get_post_meta($order->get_id(), '_alttag_extra_participants', true);
        if (is_array($extras) && !empty($extras)) {
            return;
        }

        $summary = $this->formatOrderSummary($order);
        if ($summary === '') {
            return;
        }

        echo '<p><strong>'
            . esc_html__('Participants', 'alttag-registrations') . ':</strong> '
            . esc_html($summary) . '</p>';
    }

    /**
     * Append the participants line inside SessionModule's thank-you info box
     * so Termín / Čas / Účastníci all live in one visual block.
     */
    public function renderInsideSessionBox($order, $data)
    {
        if (!($order instanceof \WC_Order)) {
            return;
        }
        $summary = $this->formatOrderSummary($order);
        if ($summary === '') {
            return;
        }
        echo '<br><strong>'
            . esc_html__('Participants', 'alttag-registrations') . ':</strong> '
            . esc_html($summary);
    }

    // =========================================================================
    // Ticket
    // =========================================================================

    public function addTicketData($pdf_data, $data, $variable_symbol)
    {
        // Multi-participant checkout creates one participant (and one ticket)
        // per attendee, so "Participants: adult × 2" on a single ticket is
        // now misleading — the ticket in the holder's hands is theirs alone.
        // The virtual field stays exposed via addTicketFieldOptions in case
        // an admin wants to opt it back in for legacy events, but by default
        // we no longer inject it into the PDF.
        return $pdf_data;
    }

    /**
     * Expose the participant-types breakdown as a virtual ticket field so
     * the Ticket Designer reorder/hide UI can still surface it if an admin
     * chooses to re-enable it (e.g. group tickets without multi-participant).
     */
    public function addTicketFieldOptions($options)
    {
        $options['participant_types'] = __('Participants', 'alttag-registrations');
        return $options;
    }

    // =========================================================================
    // Verification page
    // =========================================================================

    public function addVerificationFields($sections)
    {
        if (!isset($sections['personal']['fields'])) {
            return $sections;
        }
        if (!in_array('selected_participant_types_data', $sections['personal']['fields'], true)) {
            $sections['personal']['fields'][] = 'selected_participant_types_data';
        }
        return $sections;
    }

    public function populateVerificationData($data, $participant_id, $participant)
    {
        $data['selected_participant_types_data'] = $this->formatTypesSummary($participant_id);
        return $data;
    }

    public function formatVerificationValue($value, $field_id, $data)
    {
        return $value;
    }

    public function getVerificationLabel($label, $field_id, $data)
    {
        if ($field_id === 'selected_participant_types_data') {
            return __('Participants', 'alttag-registrations');
        }
        return $label;
    }

    // =========================================================================
    // Per-type check-in on verification page
    // =========================================================================

    /**
     * Hide the main "Register Participant" button when the participant has
     * participant types — the per-type table handles check-in instead.
     */
    public function filterShowRegisterButton($show, $customer_data)
    {
        $participant_id = $customer_data['id'] ?? null;
        if (!$participant_id) {
            return $show;
        }

        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        if ($state && !empty($state->getMeta('selected_participant_types_data'))) {
            return false;
        }
        return $show;
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    /**
     * One numeric column per participant type, instead of the raw meta.
     *
     * The raw value is a map like {"adult":3,"child":2}. As a single cell it is
     * unusable in a spreadsheet, so each type gets its own column and the counts
     * can be filtered and summed. Only types that actually occur in this export
     * are added, so a file never carries columns for types nobody booked.
     *
     * @param array $export_fields
     * @param array $meta_fields
     * @param int[] $participant_ids
     * @return array
     */
    public function extendExportFields($export_fields, $meta_fields = [], $participant_ids = [])
    {
        if (!is_array($export_fields)) {
            return $export_fields;
        }

        $labels = [];
        foreach ((array) $participant_ids as $participant_id) {
            $selected = $this->selectedTypesFor((int) $participant_id);
            if (!$selected) {
                continue;
            }
            $state = \Alttag\Registrations\ParticipantState::get((int) $participant_id);
            foreach (array_keys($selected) as $type_id) {
                if (!isset($labels[$type_id])) {
                    $labels[$type_id] = $this->typeLabel($state, (string) $type_id);
                }
            }
        }

        if (!$labels) {
            return $export_fields;
        }

        // The raw map is replaced by the per-type columns.
        unset($export_fields['selected_participant_types_data']);

        foreach ($labels as $type_id => $label) {
            $export_fields[self::EXPORT_TYPE_PREFIX . $type_id] = $label;
        }

        // Only when the multi-day module has not already put a person count in the
        // file. Both would hold the same number, and a duplicated column is worse
        // than a missing one.
        if (!isset($export_fields['total_persons'])) {
            $export_fields[self::EXPORT_TOTAL] = __('People total', 'alttag-registrations');
        }
        if (!isset($export_fields['verified_persons'])) {
            $export_fields[self::EXPORT_VERIFIED] = __('People checked in', 'alttag-registrations');
        }

        return $export_fields;
    }

    /**
     * Values for the per-type columns, and the corrected person counts.
     *
     * total_persons / verified_persons come from MultiDayModule, which counts days.
     * A booking sold per participant type has no days, so those cells said 1 and 0.
     * They are filled here from the type counts instead.
     */
    public function exportCellValue($value, $key, $participant = [], $participant_id = 0)
    {
        $selected = $this->selectedTypesFor((int) $participant_id);
        if (!$selected) {
            return $value;
        }

        if (strpos((string) $key, self::EXPORT_TYPE_PREFIX) === 0) {
            $type_id = substr((string) $key, strlen(self::EXPORT_TYPE_PREFIX));

            return isset($selected[$type_id]) ? (int) $selected[$type_id] : 0;
        }

        $state = \Alttag\Registrations\ParticipantState::get((int) $participant_id);
        $verified = $this->getVerifiedTypesData($state);

        if ($key === self::EXPORT_TOTAL || $key === 'total_persons') {
            return array_sum(array_map('intval', $selected));
        }

        if ($key === self::EXPORT_VERIFIED || $key === 'verified_persons') {
            return array_sum(array_map('intval', $verified));
        }

        if ($key === 'selected_participant_types_data') {
            return $this->formatTypesSummary((int) $participant_id);
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Admin summary box (per participant type)
    // -------------------------------------------------------------------------

    /**
     * Read-only summary of who is on this booking and how many are checked in.
     *
     * A booking sold per participant type is counted in people, not in days, so
     * the per-day box does not apply and MultiDayModule steps aside when this
     * data is present.
     *
     * Deliberately read-only: check-in happens on the verification page when the
     * QR code is scanned, this is only the view of where it stands.
     */
    public function addSummaryMetaBox($post)
    {
        if (!$this->selectedTypesFor((int) $post->ID)) {
            return;
        }

        add_meta_box(
            'alttag_participant_types_summary',
            __('Participants', 'alttag-registrations'),
            [$this, 'renderSummaryMetaBox'],
            'participant',
            'normal',
            'high'
        );
    }

    public function renderSummaryMetaBox($post)
    {
        $selected = $this->selectedTypesFor((int) $post->ID);
        if (!$selected) {
            return;
        }

        $state = \Alttag\Registrations\ParticipantState::get($post->ID);
        $verified = $this->getVerifiedTypesData($state);

        $booked_total = 0;
        $verified_total = 0;

        echo '<table class="form-table"><tbody>';

        foreach ($selected as $type_id => $booked) {
            $booked = (int) $booked;
            $done = isset($verified[$type_id]) ? (int) $verified[$type_id] : 0;
            $booked_total += $booked;
            $verified_total += $done;

            $color = $done >= $booked ? 'green' : ($done > 0 ? 'orange' : '#999');

            printf(
                '<tr><th scope="row">%s</th><td><span style="color:%s;font-weight:600">%d / %d</span></td></tr>',
                esc_html($this->typeLabel($state, (string) $type_id)),
                esc_attr($color),
                $done,
                $booked
            );
        }

        if (count($selected) > 1) {
            printf(
                '<tr style="border-top:2px solid #ddd"><th scope="row"><strong>%s</strong></th>'
                . '<td><strong>%d / %d</strong></td></tr>',
                esc_html__('Total', 'alttag-registrations'),
                $verified_total,
                $booked_total
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Booked people per type for a participant. Shape: ['adult' => 2].
     *
     * @param int $participant_id
     * @return array<string,int>
     */
    private function selectedTypesFor($participant_id): array
    {
        $state = \Alttag\Registrations\ParticipantState::get((int) $participant_id);
        $selected = $state ? $state->getMeta('selected_participant_types_data') : [];
        if (!is_array($selected)) {
            return [];
        }

        $out = [];
        foreach ($selected as $type_id => $count) {
            $count = (int) $count;
            if ($count > 0) {
                $out[sanitize_key($type_id)] = $count;
            }
        }

        return $out;
    }

    /**
     * Read verified-per-type counts. Shape: ['adult' => 2, 'child' => 1].
     */
    private function getVerifiedTypesData($state): array
    {
        $data = $state ? $state->getMeta('verified_participant_types_data') : [];
        return is_array($data) ? $data : [];
    }

    private function persistVerifiedTypesData($state, array $verified): void
    {
        $state->setMeta('verified_participant_types_data', $verified);

        // Mirror status on registration_status so the main badge reflects check-in progress
        $selected = $state->getMeta('selected_participant_types_data');
        $selected = is_array($selected) ? $selected : [];
        $total = array_sum(array_map('intval', $selected));
        $verified_total = array_sum(array_map('intval', $verified));

        $status = 'cancelled';
        if ($verified_total > 0) {
            $status = $verified_total >= $total ? 'completed' : 'partial';
        }
        $state->setMeta('registration_status', $status);
    }

    /**
     * Render per-type check-in table after the Registration Status section.
     */
    public function renderVerificationTable($section_id, $section, $customer_data)
    {
        if ($section_id !== 'status') {
            return;
        }

        $participant_id = $customer_data['id'] ?? null;
        $state = $participant_id ? \Alttag\Registrations\ParticipantState::get($participant_id) : null;
        if (!$state) {
            return;
        }

        // Livestream participants don't do in-person check-in
        if (ctx()->withParticipant($participant_id)->isLivestreamParticipant()) {
            return;
        }

        $selected = $state->getMeta('selected_participant_types_data');
        if (!is_array($selected) || empty($selected)) {
            return;
        }

        $rows = $this->buildTypeRows(
            $selected,
            (int) $state->getMeta('product_id'),
            $this->getVerifiedTypesData($state)
        );
        if (empty($rows)) {
            return;
        }

        $vs = esc_attr($customer_data['variable_symbol']);
        $total_all = 0;
        $verified_all = 0;

        $th = 'text-align:left;padding:8px;border-bottom:2px solid #ddd;';
        echo '<div style="margin-top: 20px;">';
        echo '<h4>' . esc_html__('Check-in per participant type', 'alttag-registrations') . ':</h4>';
        echo '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;">';
        echo '<tr>';
        echo '<th style="' . $th . '">' . esc_html__('Type', 'alttag-registrations') . '</th>';
        echo '<th style="' . $th . '">' . esc_html__('Persons', 'alttag-registrations') . '</th>';
        echo '<th style="' . $th . '">' . esc_html__('Status', 'alttag-registrations') . '</th>';
        echo '<th style="' . $th . '">' . esc_html__('Action', 'alttag-registrations') . '</th>';
        echo '</tr>';

        $td_open = '<td style="padding:8px;border-bottom:1px solid #eee;">';
        $td_total = '<td style="padding:8px;border-top:2px solid #ddd;">';

        foreach ($rows as $row) {
            $total = $row['total'];
            $done = $row['verified'];
            $total_all += $total;
            $verified_all += $done;
            $tid = esc_attr($row['type_id']);

            $label_register = __('Add one person', 'alttag-registrations');
            $label_remove = __('Remove one person', 'alttag-registrations');
            $label_all = __('All', 'alttag-registrations');
            $label_cancel_all = __('Cancel All', 'alttag-registrations');

            echo '<tr>';
            echo $td_open . esc_html($row['label']) . '</td>';
            echo $td_open . '<span style="color:' . $row['color'] . ';font-weight:600;">'
                . $done . '/' . $total . '</span></td>';
            echo $td_open . $this->renderStatusCell($done, $total) . '</td>';
            echo $td_open . '<div class="verify-actions">';

            if ($done < $total) {
                $this->renderActionForm($vs, 'checkin_type_' . $tid, '+1', 'verify-btn--register', $label_register);
            }
            if ($done > 0) {
                $this->renderActionForm($vs, 'checkout_type_' . $tid, '-1', 'verify-btn--cancel', $label_remove);
            }
            if ($done < $total && $total > 1) {
                $this->renderActionForm($vs, 'checkin_type_all_' . $tid, $label_all, 'verify-btn--register');
            }
            if ($done > 0 && $total > 1) {
                $this->renderActionForm($vs, 'checkout_type_all_' . $tid, $label_cancel_all, 'verify-btn--cancel');
            }
            echo '</div></td></tr>';
        }

        // Total row
        $total_color = $this->progressColor($verified_all, $total_all);
        echo '<tr style="font-weight:600;">';
        echo $td_total . esc_html__('Total', 'alttag-registrations') . '</td>';
        echo $td_total . '<span style="color:' . $total_color . ';">'
            . $verified_all . '/' . $total_all . '</span></td>';
        echo '<td colspan="2" style="padding:8px;border-top:2px solid #ddd;"></td></tr>';
        echo '</table>';

        // Bulk buttons
        echo '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">';
        if ($verified_all < $total_all) {
            $this->renderActionForm(
                $vs,
                'register_all_types',
                __('Register All', 'alttag-registrations'),
                'verify-btn--register'
            );
        }
        if ($verified_all > 0) {
            $this->renderActionForm(
                $vs,
                'cancel_all_types',
                __('Cancel All', 'alttag-registrations'),
                'verify-btn--cancel'
            );
        }
        echo '</div>';
        echo '</div>';
    }

    private function renderStatusCell(int $done, int $total): string
    {
        if ($done >= $total) {
            return '<span style="color:green;">OK ' . esc_html__('Registered', 'alttag-registrations') . '</span>';
        }
        if ($done > 0) {
            return '<span style="color:orange;">- ' . esc_html__('Partial', 'alttag-registrations') . '</span>';
        }
        return '<span style="color:#999;">- ' . esc_html__('Pending', 'alttag-registrations') . '</span>';
    }

    private function renderActionForm(
        string $vs,
        string $action,
        string $label,
        string $class,
        string $title = ''
    ): void {
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('registration_action', 'registration_nonce');
        echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
        echo '<button type="submit" name="action" value="' . esc_attr($action)
            . '" class="verify-btn verify-btn--sm ' . esc_attr($class) . '"';
        if ($title !== '') {
            echo ' title="' . esc_attr($title) . '"';
        }
        echo '>' . esc_html($label) . '</button>';
        echo '</form>';
    }

    /**
     * Handle custom verification actions for participant types.
     * Fired from TemplateHandler when an action doesn't match a built-in handler.
     */
    public function handleVerificationAction($variable_symbol, $action)
    {
        if ($action === 'register_all_types') {
            $this->registerAllTypes($variable_symbol);
            return;
        }
        if ($action === 'cancel_all_types') {
            $this->cancelAllTypes($variable_symbol);
            return;
        }

        // Prefix-matched actions: checkin/checkout (one or all) for a given type.
        // Order matters — longer "_all_" prefixes must be checked before the one-off ones.
        static $prefixes = [
            'checkin_type_all_' => ['mode' => 'bulk', 'direction' => 'in'],
            'checkout_type_all_' => ['mode' => 'bulk', 'direction' => 'out'],
            'checkin_type_' => ['mode' => 'one', 'direction' => 'in'],
            'checkout_type_' => ['mode' => 'one', 'direction' => 'out'],
        ];

        foreach ($prefixes as $prefix => $op) {
            if (strpos($action, $prefix) !== 0) {
                continue;
            }
            $type_id = sanitize_key(substr($action, strlen($prefix)));
            if ($type_id === '') {
                return;
            }
            $this->mutateVerifiedType($variable_symbol, $type_id, $op['mode'], $op['direction']);
            return;
        }
    }

    // -------------------------------------------------------------------------
    // Per-type check-in helpers (mutate verified_participant_types_data meta)
    // -------------------------------------------------------------------------

    /**
     * Resolve variable symbol to state + selected-types map. Returns null if any
     * piece is missing — callers should early-return.
     *
     * @return array{state:\Alttag\Registrations\ParticipantState,participant_id:int,selected:array}|null
     */
    private function loadStateAndTotals(string $variable_symbol): ?array
    {
        $participant = get_participant_by_variable_symbol($variable_symbol);
        if (!$participant) {
            return null;
        }
        $state = \Alttag\Registrations\ParticipantState::get($participant->ID);
        if (!$state) {
            return null;
        }
        $selected = $state->getMeta('selected_participant_types_data');
        if (!is_array($selected) || empty($selected)) {
            return null;
        }
        return [
            'state' => $state,
            'participant_id' => (int) $participant->ID,
            'selected' => $selected,
        ];
    }

    private function typeLabel($state, string $type_id): string
    {
        $product_id = (int) $state->getMeta('product_id');
        foreach ($this->selection->getTypes($product_id) as $t) {
            if ($t['id'] === $type_id) {
                return (string) $t['label'];
            }
        }
        return $type_id;
    }

    /**
     * Unified mutation entry point for a single type.
     *
     * @param string $mode      'one' (±1) or 'bulk' (set to total / 0)
     * @param string $direction 'in' (check in) or 'out' (check out)
     */
    private function mutateVerifiedType(string $variable_symbol, string $type_id, string $mode, string $direction): bool
    {
        $ctx = $this->loadStateAndTotals($variable_symbol);
        if (!$ctx) {
            return false;
        }

        $state = $ctx['state'];
        $total = (int) ($ctx['selected'][$type_id] ?? 0);
        if ($direction === 'in' && ($total <= 0 || !isset($ctx['selected'][$type_id]))) {
            return false;
        }

        $verified = $this->getVerifiedTypesData($state);
        $current = (int) ($verified[$type_id] ?? 0);

        if ($direction === 'in') {
            if ($current >= $total) {
                return false;
            }
            $new = $mode === 'bulk' ? $total : $current + 1;
        } else { // 'out'
            if ($current <= 0) {
                return false;
            }
            $new = $mode === 'bulk' ? 0 : $current - 1;
        }

        $verified[$type_id] = $new;
        $this->persistVerifiedTypesData($state, $verified);
        $state->addToHistory($this->historyLineForType($state, $type_id, $mode, $direction, $new, $total));
        return true;
    }

    private function historyLineForType(
        $state,
        string $type_id,
        string $mode,
        string $direction,
        int $new,
        int $total
    ): string {
        $label = $this->typeLabel($state, $type_id);
        $email = wp_get_current_user()->user_email;

        if ($mode === 'bulk' && $direction === 'out') {
            return sprintf(
                '%s: %s (%s)',
                $label,
                __('all persons checked out', 'alttag-registrations'),
                $email
            );
        }

        $verb = $this->historyVerb($mode, $direction);
        return sprintf('%s: %s %d/%d (%s)', $label, $verb, $new, $total, $email);
    }

    private function historyVerb(string $mode, string $direction): string
    {
        if ($mode === 'bulk' && $direction === 'in') {
            return __('all persons checked in', 'alttag-registrations');
        }
        if ($direction === 'in') {
            return __('person checked in', 'alttag-registrations');
        }
        return __('person checked out', 'alttag-registrations');
    }

    public function checkinOneForType(string $variable_symbol, string $type_id): bool
    {
        return $this->mutateVerifiedType($variable_symbol, $type_id, 'one', 'in');
    }

    public function checkoutOneForType(string $variable_symbol, string $type_id): bool
    {
        return $this->mutateVerifiedType($variable_symbol, $type_id, 'one', 'out');
    }

    public function checkinAllForType(string $variable_symbol, string $type_id): bool
    {
        return $this->mutateVerifiedType($variable_symbol, $type_id, 'bulk', 'in');
    }

    public function checkoutAllForType(string $variable_symbol, string $type_id): bool
    {
        return $this->mutateVerifiedType($variable_symbol, $type_id, 'bulk', 'out');
    }

    public function registerAllTypes(string $variable_symbol): bool
    {
        $ctx = $this->loadStateAndTotals($variable_symbol);
        if (!$ctx) {
            return false;
        }

        $rows = $this->buildTypeRows($ctx['selected'], (int) $ctx['state']->getMeta('product_id'));
        if (empty($rows)) {
            return false;
        }

        $verified = [];
        foreach ($rows as $row) {
            $verified[$row['type_id']] = $row['total'];
        }

        $this->persistVerifiedTypesData($ctx['state'], $verified);
        $ctx['state']->addToHistory(sprintf(
            __('Bulk registered all participants (%s) by %s', 'alttag-registrations'),
            $this->formatRowsSummary($rows),
            wp_get_current_user()->user_email
        ));
        return true;
    }

    public function cancelAllTypes(string $variable_symbol): bool
    {
        $ctx = $this->loadStateAndTotals($variable_symbol);
        if (!$ctx) {
            return false;
        }
        $existing = $this->getVerifiedTypesData($ctx['state']);
        if (empty(array_filter($existing))) {
            return false;
        }
        $this->persistVerifiedTypesData($ctx['state'], []);
        $ctx['state']->addToHistory(sprintf(
            __('Bulk cancelled all participants by %s', 'alttag-registrations'),
            wp_get_current_user()->user_email
        ));
        return true;
    }

    // =========================================================================
    // Export
    // =========================================================================

    public function addExportFields($fields)
    {
        if (!in_array('participant_types', $fields, true)) {
            $fields[] = 'participant_types';
        }
        return $fields;
    }

    public function formatExportValue($value, $key, $participant, $participant_id)
    {
        if ($key === 'participant_types') {
            return $this->formatTypesSummary($participant_id);
        }
        return $value;
    }
}
