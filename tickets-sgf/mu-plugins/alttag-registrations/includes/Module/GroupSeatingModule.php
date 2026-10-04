<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\GroupSeating;
use Alttag\Registrations\GroupSeatingUI;

if (!defined("ABSPATH")) {
    exit;
}

/**
 * Grouped seating: split each wave of a product into physical stations.
 *
 * The engine lives in GroupSeating (placement, capacity accounting, checkout
 * gate) and GroupSeatingUI (verify page, participant list, admin overview). This
 * module only decides WHETHER any of it runs, so a site that does not seat people
 * in groups carries no hooks at all.
 *
 * Everything a different site is likely to want differently is a filter, so it
 * can be changed from a customization plugin without touching the engine:
 *
 *   alttag_registrations_group_seating_group_count ($count, $product_id)
 *   alttag_registrations_group_seating_groups      ($labels, $product_id)
 *   alttag_registrations_group_seating_capacity    ($per_group, $product_id, $wave)
 *   alttag_registrations_group_seating_party_size  ($size, $order, $product_id)
 *
 * The station labels default to A, B, C, ... and can be renamed globally in the
 * module settings, which is the common case (Station 1 / Room red / ...).
 */
class GroupSeatingModule extends AbstractModule
{
    /** @var GroupSeating|null */
    private $engine;

    /** @var GroupSeatingUI|null */
    private $ui;

    public function getId(): string
    {
        return "group_seating";
    }

    public function getName(): string
    {
        return __("Grouped seating", "alttag-registrations");
    }

    public function getDescription(): string
    {
        return __(
            "Split each time slot into stations and place every booking into one of them, keeping a booking together.",
            "alttag-registrations"
        );
    }

    public function getSettingsTab(): ?string
    {
        return "group_seating";
    }

    public function isEnabled(): bool
    {
        return (bool) apply_filters(
            "alttag_registrations_enable_group_seating",
            parent::isEnabled()
        );
    }

    /** Per product, because usually only some products are seated in groups. */
    public function hasProductToggle(): bool
    {
        return true;
    }

    public function getSettingsFields(): array
    {
        return [
            "default_groups" => [
                "label" => __("Stations per time slot", "alttag-registrations"),
                "type" => "number",
                "default" => (string) GroupSeating::DEFAULT_GROUPS,
                "description" => __(
                    "Used when a product does not set its own number. The seats of a time slot are divided between them.",
                    "alttag-registrations"
                ),
                "product_override" => true,
                "product_meta_key" => GroupSeating::PRODUCT_GROUPS,
            ],
            "group_labels" => [
                "label" => __("Station names", "alttag-registrations"),
                "type" => "text",
                "description" => __(
                    "Comma separated, in order. Leave empty for A, B, C, ...",
                    "alttag-registrations"
                ),
                "translatable" => true,
            ],
        ];
    }

    public function registerHooks(): void
    {
        $this->engine = new GroupSeating();
        $this->engine->registerHooks();

        $this->ui = new GroupSeatingUI();
        $this->ui->registerHooks();

        add_filter(
            "alttag_registrations_group_seating_groups",
            [$this, "applyConfiguredLabels"],
            5,
            2
        );
    }

    /**
     * Rename the stations from the module settings.
     *
     * Only as many labels as there are stations are used, and a short list falls
     * back to the default letter for the remaining ones, so a half-filled
     * setting can never produce empty station names.
     *
     * @param string[] $groups
     * @param int      $product_id
     * @return string[]
     */
    public function applyConfiguredLabels($groups, $product_id)
    {
        if (!is_array($groups) || !$groups) {
            return $groups;
        }

        $configured = (string) $this->settings->get("group_seating.group_labels", "");
        if (trim($configured) === "") {
            return $groups;
        }

        $labels = array_values(array_filter(array_map("trim", explode(",", $configured)), "strlen"));
        if (!$labels) {
            return $groups;
        }

        foreach ($groups as $i => $default) {
            if (isset($labels[$i])) {
                $groups[$i] = $labels[$i];
            }
        }

        return $groups;
    }
}
