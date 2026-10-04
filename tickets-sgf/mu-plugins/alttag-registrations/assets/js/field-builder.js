jQuery(function ($) {
    'use strict';

    var config = window.fieldBuilderConfig || {};
    var $list = $('#field-builder-list');
    var $orderInput = $('#field-order');

    // Sortable
    $list.sortable({
        handle: '.field-drag-handle',
        axis: 'y',
        update: function () {
            updateFieldOrder();
        }
    });

    // Toggle accordion
    $list.on('click', '.field-row-header', function (e) {
        if ($(e.target).closest('.field-delete-btn').length) return;
        var $body = $(this).siblings('.field-row-body');
        $body.slideToggle(200);
        $(this).find('.field-toggle-btn')
            .toggleClass('dashicons-arrow-down dashicons-arrow-up');
    });

    // Delete field
    $list.on('click', '.field-delete-btn', function (e) {
        e.stopPropagation();
        if (!confirm(config.i18n.confirm_delete)) return;
        $(this).closest('.field-row').remove();
        updateFieldOrder();
    });

    // Add field
    $('#add-field-btn').on('click', function () {
        var key = prompt('Field key (lowercase, underscores):');
        if (!key) return;

        key = key.toLowerCase().replace(/[^a-z0-9_]/g, '_');

        if (!key) {
            alert(config.i18n.field_key_required);
            return;
        }

        if ($list.find('[data-key="' + key + '"]').length) {
            alert(config.i18n.field_key_exists);
            return;
        }

        var $template = $('#field-template').find('.field-row').clone();
        var html = $template.prop('outerHTML');

        // Replace __KEY__ placeholder
        html = html.replace(/__KEY__/g, key);
        var $newRow = $(html);
        $newRow.attr('data-key', key);
        $newRow.find('.field-key-label').text(key);
        $newRow.find('.field-key-input').val(key).attr('readonly', true);

        $list.append($newRow);
        updateFieldOrder();
        updateTypeDependentRows($newRow);

        // Open the new field
        $newRow.find('.field-row-body').slideDown(200);
        $newRow.find('.field-toggle-btn')
            .removeClass('dashicons-arrow-down')
            .addClass('dashicons-arrow-up');
    });

    function updateFieldOrder() {
        var keys = [];
        $list.find('.field-row').each(function () {
            keys.push($(this).attr('data-key'));
        });
        $orderInput.val(keys.join(','));
    }

    // Show/hide rows that are bound to a specific field type (e.g. options textarea for select)
    function updateTypeDependentRows($scope) {
        var type = $scope.find('select[name$="[type]"]').val();
        $scope.find('[data-show-for-type]').each(function () {
            var allowed = String($(this).attr('data-show-for-type') || '').split('|');
            $(this).toggle(allowed.indexOf(type) !== -1);
        });
    }

    // Initial state for all existing field rows
    $list.find('.field-row').each(function () {
        updateTypeDependentRows($(this));
    });

    // React to type changes inside any field row
    $list.on('change', 'select[name$="[type]"]', function () {
        updateTypeDependentRows($(this).closest('.field-row'));
    });
});
