/**
 * Multi-participant checkout — dynamic attendee fields.
 *
 * Reads the total seat count from ParticipantType counters
 * (#participant-types-wrapper .participant-type-count-input) and renders
 * (seatCount - 1) additional attendee field-sets into #alttag-extra-participants.
 *
 * Each attendee's inputs use array-notation names so PHP receives them as
 * $_POST['extra_participants'][idx][first_name|last_name|email|job_title].
 */
jQuery(function ($) {
    "use strict";

    var i18n = (window.alttagMultiParticipant && window.alttagMultiParticipant.i18n) || {};

    function _(key, fallback) {
        return i18n[key] || fallback || "";
    }

    function getTotalSeats() {
        var total = 0;
        $("#participant-types-wrapper .participant-type-count-input").each(function () {
            total += parseInt($(this).val(), 10) || 0;
        });
        return total;
    }

    function buildAttendeeBlock(idx) {
        // idx is 0-based; label is 1-based with buyer counted as #1
        var displayNum = idx + 2;
        var heading = (_("attendee_heading", "Attendee %d")).replace("%d", displayNum);

        return $(
            '<fieldset class="attendee-block" data-attendee-idx="' + idx + '">' +
                '<legend class="attendee-heading">' + heading + "</legend>" +
                '<div class="attendee-info-note">' + _("info", "") + "</div>" +
                '<p class="form-row form-row-first attendee-field-row">' +
                    '<label>' + _("first_name", "First name") +
                        ' <abbr class="required" title="required">*</abbr>' +
                    "</label>" +
                    '<span class="woocommerce-input-wrapper">' +
                        '<input type="text" class="input-text"' +
                        ' name="extra_participants[' + idx + '][first_name]"' +
                        ' required autocomplete="off" />' +
                    "</span>" +
                "</p>" +
                '<p class="form-row form-row-last attendee-field-row">' +
                    '<label>' + _("last_name", "Last name") +
                        ' <abbr class="required" title="required">*</abbr>' +
                    "</label>" +
                    '<span class="woocommerce-input-wrapper">' +
                        '<input type="text" class="input-text"' +
                        ' name="extra_participants[' + idx + '][last_name]"' +
                        ' required autocomplete="off" />' +
                    "</span>" +
                "</p>" +
                '<p class="form-row form-row-first attendee-field-row">' +
                    '<label>' + _("job_title", "Job title") +
                        ' <abbr class="required" title="required">*</abbr>' +
                    "</label>" +
                    '<span class="woocommerce-input-wrapper">' +
                        '<input type="text" class="input-text"' +
                        ' name="extra_participants[' + idx + '][job_title]"' +
                        ' placeholder="' + _("job_title_placeholder", "") + '"' +
                        ' required autocomplete="off" />' +
                    "</span>" +
                "</p>" +
                '<p class="form-row form-row-last attendee-field-row">' +
                    '<label>' + _("email", "E-mail") +
                        ' <abbr class="required" title="required">*</abbr>' +
                    "</label>" +
                    '<span class="woocommerce-input-wrapper">' +
                        '<input type="email" class="input-text"' +
                        ' name="extra_participants[' + idx + '][email]"' +
                        ' required autocomplete="off" />' +
                    "</span>" +
                "</p>" +
                '<div class="clear"></div>' +
            "</fieldset>"
        );
    }

    /**
     * Ensure a SINGLE #alttag-extra-participants container exists in the DOM,
     * positioned right after the buyer's e-mail field. PHP no longer outputs
     * it (Elementor Pro's checkout widget was echoing the billing form more
     * than once per request, multiplying the container and cloning attendee
     * blocks). Any duplicate containers left over from an earlier render are
     * dropped, keeping only the first instance.
     */
    function ensureContainer() {
        var $containers = $("#alttag-extra-participants");
        var $keep = null;

        if ($containers.length === 0) {
            $keep = $(
                '<div id="alttag-extra-participants" ' +
                'class="alttag-extra-participants" ' +
                'data-anchor="extra-participants"></div>'
            );
            var $emailRow = $("#billing_email_field");
            if ($emailRow.length) {
                $emailRow.after($keep);
            } else {
                // Fall back to end of billing wrapper if email row hasn't
                // rendered yet.
                $(".woocommerce-billing-fields__field-wrapper, .woocommerce-billing-fields").first().append($keep);
            }
            return $keep;
        }

        // De-duplicate: WordPress/Elementor may have output multiple copies
        // of the container in the same request. Keep the first, remove rest.
        $keep = $containers.first();
        $containers.slice(1).remove();

        // Ensure it sits right after buyer's email
        var $emailRow = $("#billing_email_field");
        if ($emailRow.length && $keep.prev("#billing_email_field").length === 0) {
            $emailRow.after($keep);
        }
        return $keep;
    }

    /**
     * Purge orphan attendee-field rows that landed OUTSIDE our container.
     * WooCommerce / Elementor Pro sometimes persist `extra_participants[…]`
     * inputs across an update_checkout AJAX + refresh cycle, re-emitting the
     * fields as loose <p class="attendee-field-row"> elements at the tail of
     * the billing form. Anything with our attendee-field-row class that isn't
     * a descendant of #alttag-extra-participants must go.
     */
    function purgeOrphanAttendeeRows() {
        $("p.attendee-field-row").each(function () {
            if ($(this).closest("#alttag-extra-participants").length === 0) {
                $(this).remove();
            }
        });
        // Also nuke any loose `extra_participants[…]` inputs (name-based) in
        // case markup differs from the .attendee-field-row wrapper.
        $('input[name^="extra_participants["]').each(function () {
            if ($(this).closest("#alttag-extra-participants").length === 0) {
                var $row = $(this).closest("p.form-row, .form-row");
                ($row.length ? $row : $(this)).remove();
            }
        });
    }

    function syncAttendeeBlocks() {
        purgeOrphanAttendeeRows();
        var $container = ensureContainer();
        if (!$container || !$container.length) return;

        var total = getTotalSeats();
        // Buyer is #1, so we need (total - 1) extras. Never negative.
        var extras = Math.max(0, total - 1);

        // Collect current values so a re-render doesn't wipe user input when
        // WC's ajax rebuilds the checkout markup around us.
        var $existing = $container.find(".attendee-block");
        var preserved = [];
        $existing.each(function () {
            var $b = $(this);
            preserved[$b.data("attendee-idx")] = {
                first_name: $b.find('input[name$="[first_name]"]').val() || "",
                last_name:  $b.find('input[name$="[last_name]"]').val()  || "",
                email:      $b.find('input[name$="[email]"]').val()      || "",
                job_title:  $b.find('input[name$="[job_title]"]').val()  || "",
            };
        });

        $container.empty();

        for (var i = 0; i < extras; i++) {
            var $block = buildAttendeeBlock(i);
            $container.append($block);
            if (preserved[i]) {
                $block.find('input[name$="[first_name]"]').val(preserved[i].first_name);
                $block.find('input[name$="[last_name]"]').val(preserved[i].last_name);
                $block.find('input[name$="[email]"]').val(preserved[i].email);
                $block.find('input[name$="[job_title]"]').val(preserved[i].job_title);
            }
        }
    }

    // Recompute on counter click + on WC's ajax rebuild + on initial load.
    $(document).on(
        "click",
        "#participant-types-wrapper .participant-type-minus, #participant-types-wrapper .participant-type-plus",
        function () {
            // Update on next tick so the counter's own click handler has
            // already updated the input value before we read it.
            setTimeout(syncAttendeeBlocks, 0);
        }
    );
    $(document).on(
        "change input",
        "#participant-types-wrapper .participant-type-count-input",
        syncAttendeeBlocks
    );
    $(document.body).on("updated_checkout", syncAttendeeBlocks);

    // Initial run — Elementor Pro's checkout widget renders the billing
    // form (including our container's anchor #billing_email_field) after
    // DOMReady. Fire once immediately for native WC flows, then again after
    // short delays so the ParticipantType counters injected by Elementor's
    // async render still trigger a block-count sync on a plain page-refresh.
    syncAttendeeBlocks();
    setTimeout(syncAttendeeBlocks, 250);
    setTimeout(syncAttendeeBlocks, 1000);
});
