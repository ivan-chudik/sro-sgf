<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\ParticipantState;
use Alttag\Registrations\ProductConfig;
use Alttag\Registrations\Selection\SelectionManager;
use function Alttag\Registrations\ctx;
use function Alttag\Registrations\get_default_language;
use function Alttag\Registrations\get_stream_url;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles inbound webhook imports (e.g. from Riverstream).
 * REST endpoint: POST /wp-json/alttag/v1/webhook/registrations/{key}
 */
class WebhookImportModule extends AbstractModule
{
    public function getId(): string
    {
        return 'webhook_import';
    }

    public function getName(): string
    {
        return __('Webhook Import', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('REST API endpoint that allows external apps (e.g. stream platform) to create and update participants.', 'alttag-registrations');
    }

    public function getSettingsTab(): ?string
    {
        return 'livestream';
    }

    public function getSettingsFields(): array
    {
        return [
            'webhook_key' => [
                'label' => __('Webhook Key', 'alttag-registrations'),
                'type' => 'text',
                'description' => __('Secret key for webhook authentication', 'alttag-registrations'),
            ],
        ];
    }

    public function registerHooks(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    private function getWebhookKey()
    {
        return $this->settings->get('livestream.webhook_key', '');
    }

    public function registerRoutes()
    {
        register_rest_route('alttag/v1', '/webhook/registrations/(?P<key>[a-zA-Z0-9]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handleImport'],
            'permission_callback' => [$this, 'validateKey'],
        ]);
    }

    public function validateKey($request)
    {
        $key = $this->getWebhookKey();
        if (empty($key)) {
            return false;
        }
        return $request->get_param('key') === $key;
    }

    public function handleImport($request)
    {
        $body = $request->get_json_params();

        $email = sanitize_email($body['email'] ?? '');
        if (empty($email)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Email is required',
            ], 400);
        }

        $first_name = sanitize_text_field($body['first_name'] ?? '');
        $last_name = sanitize_text_field($body['last_name'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'First name and last name are required',
            ], 400);
        }

        $language = sanitize_text_field($body['language'] ?? get_default_language());

        $product_id = null;
        $product_sku = sanitize_text_field($body['product_sku'] ?? '');
        if (!empty($product_sku)) {
            $product_id = $this->resolveProductBySku($product_sku, $language);
        }

        $raw_days = $body['selected_days']
            ?? $body['meta_data']['attendance_dates']
            ?? [];

        $existing = get_posts([
            'post_type' => 'participant',
            'meta_query' => [
                ['key' => 'email', 'value' => $email, 'compare' => '='],
            ],
            'posts_per_page' => 1,
        ]);

        if (!empty($existing)) {
            return $this->handleUpdate(
                $existing[0]->ID, $first_name, $last_name, $language, $product_id, $raw_days
            );
        }

        if (!$product_id) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'No existing participant found and no product_sku provided',
            ], 400);
        }

        return $this->handleCreate(
            $first_name, $last_name, $email, $language, $product_id, $raw_days
        );
    }

    private function handleUpdate($participant_id, $first_name, $last_name, $language, $product_id, $raw_days)
    {
        $state = ParticipantState::get($participant_id);

        if (!$product_id) {
            $product_id = $state->getMeta('product_id');
        }

        $state->setMeta('first_name', $first_name);
        $state->setMeta('last_name', $last_name);
        $state->setMeta('language', $language);

        // Use SelectionManager for validated day selection
        if ($product_id && is_array($raw_days) && !empty($raw_days)) {
            $selected_days = SelectionManager::validateSelectionsForProduct('days', $raw_days, (int) $product_id);
            if (!empty($selected_days)) {
                $days_data = array_fill_keys($selected_days, 1);
                SelectionManager::saveSelectionsToParticipant($participant_id, [
                    'selected_days' => $selected_days,
                    'selected_days_data' => $days_data,
                ]);
            }
        }

        $response = [
            'success' => true,
            'action' => 'updated',
            'participant_id' => $participant_id,
            'selected_days' => $state->selectedDays(),
        ];

        return new \WP_REST_Response(
            apply_filters('alttag_registrations_webhook_import_response', $response),
            200
        );
    }

    private function handleCreate($first_name, $last_name, $email, $language, $product_id, $raw_days)
    {
        $context = ctx()->withProduct($product_id);

        $participant_id = wp_insert_post([
            'post_type' => 'participant',
            'post_status' => 'publish',
            'post_title' => $first_name . ' ' . $last_name,
        ]);

        if (is_wp_error($participant_id)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Failed to create participant',
            ], 500);
        }

        // Use SelectionManager for validated day selection
        $selected_days = is_array($raw_days)
            ? SelectionManager::validateSelectionsForProduct('days', $raw_days, (int) $product_id)
            : [];
        $days_data = array_fill_keys($selected_days, 1);

        $order_data = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'language' => $language,
            'product_id' => $product_id,
            'product_name' => $context->productName(),
            'selected_days' => $selected_days,
            'selected_days_data' => $days_data,
        ];

        $state = ParticipantState::fromOrderData($participant_id, $order_data);
        foreach ($order_data as $key => $value) {
            $state->setMeta($key, $value);
        }

        $variable_symbol = $this->generateVariableSymbol($participant_id, $product_id);
        $state->setMeta('variable_symbol', $variable_symbol);

        do_action('alttag_registrations_participant_create', $participant_id, $order_data);

        $response = [
            'success' => true,
            'action' => 'created',
            'participant_id' => $participant_id,
            'variable_symbol' => $variable_symbol,
            'selected_days' => $selected_days,
        ];

        if ($state->isLivestream()) {
            $response['stream_url'] = get_stream_url();
        }

        return new \WP_REST_Response(
            apply_filters('alttag_registrations_webhook_import_response', $response),
            201
        );
    }

    private function generateVariableSymbol($participant_id, $product_id)
    {
        $sku = ctx()->withProduct($product_id)->productSku();
        $prefix = strtoupper(str_replace(['-', '_'], '', $sku));
        if (strlen($prefix) > 10) {
            $prefix = substr($prefix, 0, 10);
        }
        return $prefix . $participant_id;
    }

    private function resolveProductBySku($sku, $language)
    {
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return null;
        }

        if (ctx()->hasPolylang() && function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($product_id);
            if (isset($translations[$language])) {
                return $translations[$language];
            }
        }

        return $product_id;
    }
}
