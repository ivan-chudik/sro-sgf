jQuery(function ($) {
    "use strict";

    var config = window.checkoutFieldsConfig || {};

    function getFieldInput(fieldKey) {
        var selectors = [
            '[name="' + fieldKey + '"]',
            '[name="' + fieldKey + '[]"]',
            '[name="billing_' + fieldKey + '"]',
            '[name="billing_' + fieldKey + '[]"]'
        ];

        return $(selectors.join(", "));
    }

    function getFieldValue($input) {
        if (!$input.length) {
            return "";
        }

        if ($input.first().is(":checkbox")) {
            if ($input.length > 1) {
                return $input.filter(":checked").map(function () {
                    return $(this).val();
                }).get();
            }

            return $input.first().is(":checked") ? ($input.first().val() || "1") : "";
        }

        if ($input.first().is(":radio")) {
            return $input.filter(":checked").val() || "";
        }

        return $input.val();
    }

    function normalizeValues(value) {
        if (Array.isArray(value)) {
            return value.map(function (item) {
                return String(item);
            });
        }

        if (value === undefined || value === null || value === "") {
            return [];
        }

        return [String(value)];
    }

    function shouldShow(triggerValue, fieldConfig) {
        var currentValues = normalizeValues(triggerValue);
        var expected = fieldConfig.show_when_values || fieldConfig.values || fieldConfig.show_when || fieldConfig.value || [];
        var expectedValues = normalizeValues(expected);

        if (!expectedValues.length) {
            return currentValues.length > 0;
        }

        for (var i = 0; i < currentValues.length; i++) {
            if (expectedValues.indexOf(currentValues[i]) !== -1) {
                return true;
            }
        }

        return false;
    }

    function resolveTargets(fieldConfig) {
        var selectors = [];

        if (fieldConfig.target_selector) {
            selectors.push(fieldConfig.target_selector);
        }

        if (Array.isArray(fieldConfig.target_selectors)) {
            selectors = selectors.concat(fieldConfig.target_selectors);
        }

        if (fieldConfig.target_field) {
            selectors.push('[name="' + fieldConfig.target_field + '"]');
            selectors.push('[name="' + fieldConfig.target_field + '[]"]');
        }

        if (Array.isArray(fieldConfig.target_fields)) {
            fieldConfig.target_fields.forEach(function (field) {
                selectors.push('[name="' + field + '"]');
                selectors.push('[name="' + field + '[]"]');
            });
        }

        return selectors.join(", ");
    }

    function toggleTargetVisibility(selector, visible) {
        if (!selector) {
            return;
        }

        $(selector).each(function () {
            var $target = $(this);
            var $container = $target.closest(".form-row, .woocommerce-input-wrapper, .field, p");

            if (!$container.length) {
                $container = $target;
            }

            $container.toggle(visible);
            $container.find(":input").prop("disabled", !visible);
        });
    }

    function bindToggle(fieldConfig) {
        if (!fieldConfig || !fieldConfig.field_key) {
            return;
        }

        var $input = getFieldInput(fieldConfig.field_key);
        if (!$input.length) {
            return;
        }

        var targetSelector = resolveTargets(fieldConfig);
        if (!targetSelector) {
            return;
        }

        var update = function () {
            toggleTargetVisibility(targetSelector, shouldShow(getFieldValue($input), fieldConfig));
        };

        $input.off(".alttagFieldBuilder").on("change.alttagFieldBuilder", update);
        update();
    }

    function init() {
        Object.keys(config.toggle_configs || {}).forEach(function (key) {
            bindToggle(config.toggle_configs[key]);
        });

        Object.keys(config.ajax_configs || {}).forEach(function (key) {
            bindToggle(config.ajax_configs[key]);
        });
    }

    $(document.body).on("updated_checkout", init);
    init();
});
