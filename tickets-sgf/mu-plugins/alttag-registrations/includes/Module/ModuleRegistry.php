<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class ModuleRegistry
{
    /** @var AbstractModule[] All registered modules */
    private $modules = [];

    /** @var AbstractModule[] Only enabled/booted modules */
    private $active = [];

    /** @var Settings */
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Register a module. Call before boot().
     */
    public function register(AbstractModule $module): void
    {
        $module->setSettings($this->settings);
        $this->modules[$module->getId()] = $module;
    }

    /**
     * Boot all enabled modules. Call once during Core init.
     */
    public function boot(): void
    {
        // Allow external plugins to register modules
        do_action('alttag_registrations_register_modules', $this);

        // Determine which modules are enabled
        foreach ($this->modules as $id => $module) {
            if ($module->isEnabled()) {
                $this->active[$id] = $module;
            }
        }

        // Register hooks for active modules
        foreach ($this->active as $module) {
            $module->registerHooks();
        }
    }

    /**
     * Get a registered module by ID (even if disabled).
     */
    public function get(string $id): ?AbstractModule
    {
        return $this->modules[$id] ?? null;
    }

    /**
     * Check if a module is active (enabled and booted).
     */
    public function isActive(string $id): bool
    {
        return isset($this->active[$id]);
    }

    /**
     * Get all active modules.
     *
     * @return AbstractModule[]
     */
    public function getActive(): array
    {
        return $this->active;
    }

    /**
     * Get all registered modules.
     *
     * @return AbstractModule[]
     */
    public function getAll(): array
    {
        return $this->modules;
    }

    /**
     * Collect settings fields from all registered modules.
     * Used by Settings to build the admin UI.
     *
     * @return array ['tab_name' => ['field_key' => [...], ...], ...]
     */
    public function collectSettingsFields(): array
    {
        $fields = [];

        foreach ($this->modules as $module) {
            $tab = $module->getSettingsTab();
            if ($tab === null) {
                continue;
            }

            // Module toggle always appears in the "modules" tab so the admin
            // can switch modules on/off regardless of their current state.
            if (!isset($fields['modules'])) {
                $fields['modules'] = [];
            }
            $fields['modules']['enable_' . $module->getId()] = [
                'label' => $module->getName(),
                'type' => 'checkbox',
                'description' => $module->getDescription(),
            ];

            // Skip a disabled module's own settings + product_override fields —
            // otherwise its product meta inputs (e.g. "Hotel: cena pre 1 osobu")
            // leak into the product Event Details tab via
            // Settings::getProductOverrideFields() even though the module isn't
            // running.
            if (!$module->isEnabled()) {
                continue;
            }

            // Module-specific settings go to the module's own tab
            foreach ($module->getSettingsFields() as $key => $field) {
                if (isset($field['type'])) {
                    // Flat field definition — add to the module's settings tab
                    if (!isset($fields[$tab])) {
                        $fields[$tab] = [];
                    }
                    $fields[$tab][$key] = $field;
                } else {
                    // Nested: $key is a sub-tab name, $field is an array of field definitions
                    if (!isset($fields[$key])) {
                        $fields[$key] = [];
                    }
                    $fields[$key] = array_merge($fields[$key], $field);
                }
            }
        }

        return $fields;
    }
}
