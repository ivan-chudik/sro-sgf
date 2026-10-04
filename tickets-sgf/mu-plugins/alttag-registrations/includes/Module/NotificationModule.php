<?php

namespace Alttag\Registrations\Module;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sales threshold notifications.
 * Sends email when registration count exceeds configured thresholds.
 */
class NotificationModule extends AbstractModule
{
    public function getId(): string
    {
        return 'notifications';
    }

    public function getName(): string
    {
        return __('Sales Notifications', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('Send email notifications when registration count reaches configured thresholds.', 'alttag-registrations');
    }

    public function getSettingsTab(): ?string
    {
        return 'notifications';
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('modules.enable_' . $this->getId(), false);
    }

    public function getSettingsFields(): array
    {
        return [
            'sales_email' => [
                'label' => __('Notification Email', 'alttag-registrations'),
                'type' => 'email',
                'description' => __('Email address to receive sales threshold notifications', 'alttag-registrations'),
            ],
            'inperson_threshold' => [
                'label' => __('In-person Threshold', 'alttag-registrations'),
                'type' => 'number',
                'description' => __('Send notification when in-person registrations reach this count', 'alttag-registrations'),
            ],
            'livestream_threshold' => [
                'label' => __('Livestream Threshold', 'alttag-registrations'),
                'type' => 'number',
                'description' => __('Send notification when livestream registrations reach this count', 'alttag-registrations'),
            ],
        ];
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_order_status_completed', [$this, 'checkThresholds']);
        add_action('woocommerce_order_status_processing', [$this, 'checkThresholds']);
    }

    private function getConfigs()
    {
        $email = $this->settings->get('notifications.sales_email', '');
        if (empty($email)) {
            return [];
        }

        $configs = [];

        $livestream_threshold = (int) $this->settings->get('notifications.livestream_threshold', 0);
        if ($livestream_threshold > 0) {
            $configs['livestream'] = [
                'threshold' => $livestream_threshold,
                'option' => 'alttag_online_product_sales_notification_sent',
                'check_product' => 'Alttag\Registrations\is_online_attendance_product',
                'label' => 'Livestream product',
            ];
        }

        $inperson_threshold = (int) $this->settings->get('notifications.inperson_threshold', 0);
        if ($inperson_threshold > 0) {
            $configs['inperson'] = [
                'threshold' => $inperson_threshold,
                'option' => 'alttag_inperson_product_sales_notification_sent',
                'check_product' => 'Alttag\Registrations\is_inperson_attendance_product',
                'label' => 'In-person product',
            ];
        }

        return $configs;
    }

    public function checkThresholds($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($this->getConfigs() as $type => $config) {
            $this->checkProductThreshold($order, $config);
        }
    }

    private function checkProductThreshold($order, $config)
    {
        if (get_option($config['option'])) {
            return;
        }

        $has_matching = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && call_user_func($config['check_product'], $product)) {
                $has_matching = true;
                break;
            }
        }

        if (!$has_matching) {
            return;
        }

        $total_sales = $this->getTotalProductSales($config['check_product']);
        if ($total_sales < $config['threshold']) {
            return;
        }

        $this->sendNotification($total_sales, $config);
        update_option($config['option'], time());
    }

    private function getTotalProductSales($check_product)
    {
        $total = 0;

        $orders = wc_get_orders([
            'status' => ['completed', 'processing'],
            'limit' => -1,
            'return' => 'ids',
        ]);

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && call_user_func($check_product, $product)) {
                    $total += $item->get_quantity();
                }
            }
        }

        return $total;
    }

    private function sendNotification($total_sales, $config)
    {
        $email = $this->settings->get('notifications.sales_email', '');
        if (empty($email)) {
            return;
        }

        $subject = sprintf(
            '[%s] %s sales exceeded %d',
            get_bloginfo('name'),
            $config['label'],
            $config['threshold']
        );

        $message = sprintf(
            "%s sales have exceeded the threshold of %d.\n\n" .
            "Current total: %d products sold.\n\n" .
            "This is an automated notification from %s.",
            $config['label'],
            $config['threshold'],
            $total_sales,
            home_url()
        );

        wp_mail($email, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
    }
}
