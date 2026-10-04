/**
 * Session Selection - date dropdown with optional time slot sub-dropdown.
 */
(function($) {
    'use strict';

    if (typeof window.selectionConfigs === 'undefined' || !window.selectionConfigs.sessions) {
        return;
    }

    /**
     * Read the config out of the global on every use instead of keeping the one
     * the page loaded with: switching the location replaces
     * window.selectionConfigs with the new product's dates and waves
     * (checkout-product-group.js) and only then triggers updated_checkout. A
     * config captured at load would keep answering with the old product's waves,
     * which is how a time picked in Bratislava ended up rebuilt for Kosice.
     */
    function sessionsConfig() {
        return (window.selectionConfigs || {}).sessions || {};
    }

    // Remember selected slot across checkout refreshes
    var rememberedSlot = '';

    // The date the slots were last built for. What was remembered belongs to
    // that date, so a date that changed under the script - another location,
    // another product's single date - is not answered with the old pick.
    var renderedDate = null;

    /**
     * The chosen date, whatever it is rendered as: single-date products print
     * #selected_session as a hidden input rather than a select, and a product
     * without slots may not print the id at all.
     */
    function selectedDate() {
        var $date = $('#selected_session');

        return $date.length ? ($date.val() || '') : '';
    }

    function getDateConfig(dateValue) {
        var options = sessionsConfig().options || [];

        for (var i = 0; i < options.length; i++) {
            if (options[i].date === dateValue) {
                return options[i];
            }
        }
        return null;
    }

    /** The slot entry of a date config, or null. */
    function getSlotConfig(dateConfig, time) {
        var slots = (dateConfig && dateConfig.slots) || [];

        for (var i = 0; i < slots.length; i++) {
            if (slots[i].time === time) {
                return slots[i];
            }
        }
        return null;
    }

    /** How many people this order seats - the counters are the only source. */
    function partySize() {
        var total = 0;

        $('input[name^="selected_participant_types_data"]').each(function() {
            var count = parseInt($(this).val(), 10);
            if (!isNaN(count) && count > 0) {
                total += count;
            }
        });

        return total > 0 ? total : 1;
    }

    /**
     * The counted noun for this number, declined by the server.
     *
     * PHP ships one form per count (see getJsConfig), so picking the right
     * Slovak ending is a lookup here and not a plural rule reimplemented in JS.
     */
    function declined(forms, n) {
        var template = (forms && (forms[n] || forms.more)) || '%d';

        return template.replace('%d', n);
    }

    /**
     * Warn before payment when the picked time has the seats but not together.
     *
     * A wave whose free seats sit in different groups reads as available in the
     * dropdown, and seating never splits a party, so the family would only find
     * out at the payment step. Say it while the time can still be changed.
     */
    function updateSlotWarning(dateConfig) {
        var labels = sessionsConfig().labels || {};
        var $slotWrap = $('.session-slot-field');
        var time = $('#selected_session_slot').val() || '';
        var slot = time ? getSlotConfig(dateConfig, time) : null;
        var party = partySize();

        if (!slot || !(slot.max_block > 0) || slot.max_block >= party) {
            $('.session-slot-warning').remove();
            return;
        }

        var $warning = $('.session-slot-warning');
        if (!$warning.length) {
            $warning = $('<div class="session-slot-warning"></div>').insertAfter($slotWrap);
        }

        // Fragmented slot: seats are split across groups. Un-fragmented slot
        // (largest block == everything left): the party simply does not fit,
        // "split across groups" would be nonsense.
        var msg;
        if (slot.max_block < slot.remaining && labels.party_too_big) {
            msg = labels.party_too_big
                .replace('%1$s', declined(sessionsConfig().seat_forms, slot.remaining))
                .replace('%2$s', declined(sessionsConfig().seat_forms, slot.max_block))
                .replace('%3$s', declined(sessionsConfig().people_forms, party));
        } else if (labels.party_no_room) {
            msg = labels.party_no_room
                .replace('%1$s', declined(sessionsConfig().seat_forms, slot.remaining))
                .replace('%2$s', declined(sessionsConfig().people_forms, party));
        } else {
            $warning.remove();
            return;
        }

        $warning.text(msg);
    }

    function updateSlotDropdown(restoreSlot) {
        var labels = sessionsConfig().labels || {};
        var dateValue = selectedDate();
        var $slotWrap = $('.session-slot-field');
        var $slotSelect = $('#selected_session_slot');

        // Whatever this render ends on, it is the render of this date.
        renderedDate = dateValue;

        if (!dateValue || !$slotWrap.length) {
            $slotWrap.hide();
            updateSlotWarning(null);
            return;
        }

        var dateConfig = getDateConfig(dateValue);
        if (!dateConfig || !dateConfig.has_slots || !dateConfig.slots.length) {
            $slotWrap.hide();
            $slotSelect.val('');
            updateSlotWarning(null);
            return;
        }

        // Save current selection before rebuild
        var currentSlot = restoreSlot || $slotSelect.val() || rememberedSlot;

        // Rebuild slot options
        $slotSelect.empty();
        $slotSelect.append('<option value="">' + (labels.select || 'Select') + '</option>');

        for (var i = 0; i < dateConfig.slots.length; i++) {
            var slot = dateConfig.slots[i];
            var text = slot.label || slot.time;

            if (slot.disabled) {
                if (slot.sold_out) {
                    text += ' - ' + (labels.sold_out || 'Sold out');
                } else {
                    text += ' - ' + (labels.closed || 'Closed');
                }
            } else if (slot.capacity > 0) {
                // "free seats 90/90": the total matters as much as what is left.
                var seats = labels.seats || 'free seats %1$s/%2$s';
                text += ' - ' + seats
                    .replace('%1$s', slot.remaining)
                    .replace('%2$s', slot.capacity);

                // Fragmented wave: the free total alone promises a seat block
                // that no single booking can get. Kept out of the option label
                // on purpose (2026-09-10); the inline warning below still fires.
            }

            var $opt = $('<option></option>')
                .val(slot.time)
                .text(text)
                .prop('disabled', slot.disabled);

            $slotSelect.append($opt);
        }

        // Restore previous selection, but only a time this date actually runs:
        // val() on a value the select does not carry leaves it on no option at
        // all, which reads as picked to nobody and submits as empty.
        if (currentSlot && hasSlot($slotSelect, currentSlot)) {
            $slotSelect.val(currentSlot);
        }

        $slotWrap.show();
        updateSlotWarning(dateConfig);
    }

    /** Whether the rebuilt select carries this time. */
    function hasSlot($slotSelect, time) {
        return $slotSelect.children('option').filter(function() {
            return this.value === time;
        }).length > 0;
    }

    $(document).on('change', '#selected_session', function() {
        rememberedSlot = ''; // Reset slot when date changes
        updateSlotDropdown();
        $('body').trigger('update_checkout');
    });

    $(document).on('change', '#selected_session_slot', function() {
        rememberedSlot = $(this).val();
        // update_checkout answers in its own time; the warning belongs to the
        // click that caused it.
        updateSlotWarning(getDateConfig(selectedDate()));
        $('body').trigger('update_checkout');
    });

    // The counters debounce their update_checkout, so the warning would trail a
    // second behind the party it is about. Re-read the counters right away.
    $(document).on('click', '.participant-type-minus, .participant-type-plus', function() {
        setTimeout(function() {
            updateSlotWarning(getDateConfig(selectedDate()));
        }, 0);
    });

    // Re-init after WooCommerce checkout update - restore slot
    $(document.body).on('updated_checkout', function() {
        // The date the checkout came back with decides whether the remembered
        // wave still means anything: a location switch brings another product's
        // dates, and its 18:05 is not the one that was picked.
        if (selectedDate() !== renderedDate) {
            rememberedSlot = '';
        }

        updateSlotDropdown(rememberedSlot);
    });

    // Initial state
    updateSlotDropdown();

})(jQuery);
