<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\ParticipantState;
use Alttag\Registrations\RegistrationContext;
use function Alttag\Registrations\ctx;
use function Alttag\Registrations\is_livestream_enabled;
use function Alttag\Registrations\is_livestream_user;
use function Alttag\Registrations\get_livestream_webhook_url;

if (!defined('ABSPATH')) {
    exit;
}

class LivestreamModule extends AbstractModule
{
    public const NOT_GRANTED_STATE = 'not_granted';
    public const GRANTED_STATE = 'granted';
    public const PENDING_STATE = 'pending';
    public const FAILED_STATE = 'failed';

    public function getId(): string
    {
        return 'livestream';
    }

    public function getName(): string
    {
        return __('Livestream', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('Enable online/livestream attendance with access management.', 'alttag-registrations');
    }

    public function getSettingsTab(): ?string
    {
        return 'livestream';
    }

    public function isEnabled(): bool
    {
        $setting = parent::isEnabled();
        return apply_filters('alttag_registrations_enable_livestream', $setting);
    }

    public function getSettingsFields(): array
    {
        return [
            'stream_url' => [
                'label' => __('Stream URL', 'alttag-registrations'),
                'type' => 'text',
                'description' => __('URL of the livestream page', 'alttag-registrations'),
                'product_override' => true,
                'product_meta_key' => '_livestream_url',
            ],
            'stream_link_text' => [
                'label' => __('Stream Link Text', 'alttag-registrations'),
                'type' => 'text',
                'description' => __('Text displayed for the stream link', 'alttag-registrations'),
                'translatable' => true,
                'product_override' => true,
                'product_meta_key' => '_livestream_link_text',
            ],
            'webhook_url' => [
                'label' => __('Webhook URL', 'alttag-registrations'),
                'type' => 'text',
                'description' => __('URL to send livestream access webhooks', 'alttag-registrations'),
                'product_override' => true,
                'product_meta_key' => '_livestream_webhook_url',
            ],
        ];
    }

    public function registerHooks(): void
    {
        add_action('alttag_registrations_participant_create', [$this, 'maybeGrantLiveStreamAccess'], 10, 2);
        add_action('alttag_registrations_participant_update', [$this, 'maybeGrantLiveStreamAccess'], 10, 2);
        add_filter('alttag_registrations_livestream_webhook_body', [$this, 'enrichWebhookBody'], 5, 2);
    }

    public function enrichWebhookBody($body, $data)
    {
        $participant_id = $data['participant_id'] ?? null;
        if (!$participant_id) {
            return $body;
        }

        $context = ctx()->withParticipant($participant_id);
        if (!$context->participant()) {
            return $body;
        }

        $body['language'] = $context->participantLanguage();

        $product = $context->product();
        if ($product) {
            $body['product_sku'] = $product->sku();
            $body['product_name'] = $product->name();
        }


        return $body;
    }

    public function maybeGrantLiveStreamAccess($participant_id, $order_data)
    {
        if (!is_livestream_enabled()) {
            return;
        }

        $context = ctx()->withParticipant($participant_id);

        $is_livestream = false;
        if (isset($order_data['is_livestream_user'])) {
            $is_livestream = filter_var($order_data['is_livestream_user'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $is_livestream = is_livestream_user($participant_id);
        }

        if (!$is_livestream) {
            return false;
        }

        $order = $context->order();
        if ($order && $order->get_status() !== 'completed') {
            return false;
        }

        $should_grant = apply_filters('alttag_registrations_should_grant_livestream_access', true, $participant_id, $order_data);
        if (!$should_grant) {
            return false;
        }

        return $this->sendRiverstreamWebhook($context, 'live', $order_data);
    }

    /**
     * Send the Riverstream access webhook for a participant. Shared between
     * livestream (live event access) and recording (post-event recording
     * access) flows. Updates per-access-type meta + registration history.
     * Returns true on HTTP 2xx.
     *
     * @param RegistrationContext $context Context bound to the participant
     * @param string $access_type 'live' | 'recording'
     * @param array $order_data Optional order data overriding context values
     * @return bool
     */
    public function sendRiverstreamWebhook(RegistrationContext $context, string $access_type, array $order_data = [])
    {
        $state = $context->participant();
        if (!$state) {
            return false;
        }

        $meta_key = $access_type === 'recording' ? 'recording_access' : 'livestream_access';

        $email = $state->email ?: ($order_data['email'] ?? '');
        if (empty($email)) {
            return false;
        }

        if ($state->getMeta($meta_key) === self::GRANTED_STATE) {
            return true;
        }

        $state->setMeta($meta_key, self::PENDING_STATE);

        $url = get_livestream_webhook_url();
        if (!$url) {
            $state->setMeta($meta_key, self::FAILED_STATE);
            return false;
        }

        $participant_data = $this->getWebhookParticipantData($context, $order_data, $access_type);
        $webhook_result = $this->sendWebhook($url, $participant_data);

        if ($webhook_result) {
            $state->setMeta($meta_key, self::GRANTED_STATE);
            try {
                $message = $access_type === 'recording'
                    ? sprintf(__('Recording access granted for %s', 'alttag-registrations'), $email)
                    : sprintf(__('Livestream access granted for %s', 'alttag-registrations'), $email);
                $state->addToHistory($message);
            } catch (\Exception $e) {
                error_log('Failed to add to registration history: ' . $e->getMessage());
            }
            return true;
        }

        $state->setMeta($meta_key, self::FAILED_STATE);
        return false;
    }

    private function getWebhookParticipantData(RegistrationContext $context, $order_data, $access_type = 'live')
    {
        $state = $context->participant();
        $first_name = $order_data['first_name'] ?? $state->first_name;
        $last_name = $order_data['last_name'] ?? $state->last_name;
        $meta_data = apply_filters(
            'alttag_registrations_livestream_webhook_participant_data',
            [],
            $state->id,
            $order_data
        );
        if (!is_array($meta_data)) {
            $meta_data = [];
        }
        $meta_data['access_type'] = $access_type;

        return [
            'email' => $order_data['email'] ?? $state->email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => $state->getFullName(),
            'participant_id' => $state->id,
            'order_id' => $order_data['order_id'] ?? $state->order_id,
            'access_type' => $access_type,
            'meta_data' => $meta_data,
        ];
    }

    private function buildWebhookBody($data)
    {
        $enriched = apply_filters('alttag_registrations_livestream_webhook_body', [
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'full_name' => $data['full_name'],
            'meta_data' => $data['meta_data'] ?? [],
        ], $data);

        return [
            'email'    => $enriched['email'],
            'name'     => trim(($enriched['first_name'] ?? '') . ' ' . ($enriched['last_name'] ?? '')),
            'language' => $enriched['language'] ?? null,
            'meta'     => array_filter([
                'participant_id'   => $data['participant_id'] ?? null,
                'order_id'         => $data['order_id'] ?? null,
                'product_sku'      => $enriched['product_sku'] ?? null,
                'product_name'     => $enriched['product_name'] ?? null,
                'access_type'      => $data['access_type'] ?? null,
            ] + ($enriched['meta_data'] ?? [])),
        ];
    }

    private function sendWebhook($url, $data)
    {
        $body = $this->buildWebhookBody($data);

        $response = wp_remote_post($url, [
            'body' => json_encode($body),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code === 200 || $code === 201;
    }
}
