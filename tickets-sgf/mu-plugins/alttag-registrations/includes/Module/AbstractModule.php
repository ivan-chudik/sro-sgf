<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Settings;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractModule
{
    /** @var Settings */
    protected $settings;

    /**
     * Unique module identifier. Used as settings key prefix.
     */
    abstract public function getId(): string;

    /**
     * Human-readable name for Settings UI.
     */
    abstract public function getName(): string;

    /**
     * Register WordPress hooks. Only called if isEnabled() returns true.
     */
    abstract public function registerHooks(): void;

    /**
     * Short description shown next to the module toggle in Settings.
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * Settings tab where this module's toggle appears.
     * Return null if the module has no toggle (always active).
     */
    public function getSettingsTab(): ?string
    {
        return null;
    }

    /**
     * Is this module enabled?
     * Default: check settings toggle at {tab}.enable_{id}
     */
    public function isEnabled(): bool
    {
        if ($this->getSettingsTab() === null) {
            return true;
        }
        return (bool) $this->settings->get('modules.enable_' . $this->getId(), false);
    }

    /**
     * Additional settings fields this module contributes to its tab.
     * Return array in same format as Settings::getTabFields() entries.
     */
    public function getSettingsFields(): array
    {
        return [];
    }

    /**
     * Whether this module can be toggled per product.
     * Override and return true in modules that support per-product activation.
     */
    public function hasProductToggle(): bool
    {
        return false;
    }

    /**
     * Check if this module is enabled for a specific product.
     * Per-product meta: '1' = force enable, '0' = force disable, '' = use global.
     */
    public function isEnabledForProduct(int $product_id): bool
    {
        if (!$this->hasProductToggle()) {
            return $this->isEnabled();
        }

        $meta = get_post_meta($product_id, '_alttag_module_' . $this->getId(), true);
        if ($meta === '1') {
            return true;
        }
        if ($meta === '0') {
            return false;
        }

        return $this->isEnabled();
    }

    /**
     * Module dependencies (by ID). Registry ensures dependencies boot first.
     */
    public function getDependencies(): array
    {
        return [];
    }

    /**
     * Inject Settings instance. Called by ModuleRegistry before boot.
     */
    public function setSettings(Settings $settings): void
    {
        $this->settings = $settings;
    }
}
