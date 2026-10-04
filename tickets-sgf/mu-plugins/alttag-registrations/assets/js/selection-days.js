jQuery(function ($) {
    "use strict";

    // Matrix pricing mode: when participant types define per-type day tiers,
    // the day section becomes "select-only" — all pricing lives in the
    // participant types section to avoid showing competing totals.
    function isMatrixPricingActive() {
        var pt = window.selectionConfigs && window.selectionConfigs.participant_types;
        if (!pt || !pt.type_tiers) return false;
        var tiers = pt.type_tiers;
        for (var k in tiers) {
            if (Object.prototype.hasOwnProperty.call(tiers, k)
                && tiers[k] && Object.keys(tiers[k]).length > 0) {
                return true;
            }
        }
        return false;
    }

    // Layered tier pricing calculation.
    // If a bundle price for the current number of selected days does not
    // exist, fall back to the actual per-day prices for that layer.
    function calculateLayeredPrice(selectedEntries, tiers, dayPrices) {
        if (!selectedEntries.length) return 0;

        var sorted = selectedEntries
            .map(function (entry) { return parseInt(entry.count, 10) || 1; })
            .sort(function (a, b) { return a - b; });

        var total = 0;
        var prev = 0;
        var numTotalDays = sorted.length;

        for (var i = 0; i < numTotalDays; i++) {
            var layerPersons = sorted[i] - prev;
            if (layerPersons <= 0) continue;

            var layerDays = numTotalDays - i;

            if (tiers[layerDays]) {
                total += tiers[layerDays] * layerPersons;
            } else {
                selectedEntries.forEach(function (entry) {
                    var count = parseInt(entry.count, 10) || 1;
                    if (count >= sorted[i]) {
                        total += (dayPrices[entry.date] || 0) * layerPersons;
                    }
                });
            }

            prev = sorted[i];
        }

        return total;
    }

    // Bind one wrapper instance — handlers are scoped to it, so multiple
    // multi-day products in the same cart each get their own state.
    function bindDaySelection($wrapper) {
        if (!$wrapper.length || $wrapper.data("alttagDayBound")) {
            return;
        }
        $wrapper.data("alttagDayBound", true);

        var globalConfig = (typeof multiDayConfig !== "undefined" && multiDayConfig) ? multiDayConfig : {};
        var dayPrices = $wrapper.data("dayPrices") || globalConfig.day_prices || {};
        var tiers = $wrapper.data("tiers") || globalConfig.tiers || {};
        var currency = globalConfig.currency || "";
        var daysLabel = globalConfig.days_label || "days";
        var totalLabel = globalConfig.total_label || "Total";

        var requiredCount = parseInt($wrapper.attr("data-required-count"), 10) || 0;
        var hasOwnPricing = Object.keys(dayPrices).length > 0 || Object.keys(tiers).length > 0;

        var $checkboxes = $wrapper.find('input[type="checkbox"]');
        var $pricingItems = $wrapper.find(".day-selection-pricing-item");
        var $totalDisplay = $wrapper.find(".day-selection-total");
        var $errorDisplay = $wrapper.find(".day-selection-error");
        var matrixMode = isMatrixPricingActive();

        if (matrixMode) {
            // In matrix mode the participant types section owns all pricing.
            // The day section is a pure selector — strip price visuals to
            // prevent confusing competing totals.
            $wrapper.find(".day-selection-pricing").hide();
            $wrapper.find(".day-price").hide();
            $totalDisplay.hide();
        }

        function formatPrice(amount) {
            return amount.toFixed(2).replace(".", ",") + " " + currency;
        }

        function update() {
            var checkedBoxes = $checkboxes.filter(":checked");
            var numDays = checkedBoxes.length;

            $checkboxes.each(function () {
                $(this).closest(".day-checkbox").toggleClass("checked", $(this).is(":checked"));
            });

            if (requiredCount > 0) {
                // The ticket covers a fixed number of days: once that many are
                // picked the rest are locked, so the visitor cannot buy more
                // days than the ticket includes.
                $checkboxes.each(function () {
                    var box = this;
                    $(box).prop("disabled", !box.checked && numDays >= requiredCount);
                    $(box).closest(".day-checkbox").toggleClass("disabled", box.disabled);
                });
                $wrapper.find(".day-selection-required")
                    .toggleClass("satisfied", numDays === requiredCount);
            }

            if (matrixMode) {
                // Day section is select-only — pricing display lives in
                // participant-types section.
                $errorDisplay.removeClass("visible");
                return;
            }

            if (!hasOwnPricing) {
                // Price comes from the product itself (incl. any scheduled
                // sale), not from the days — the order review shows it, so
                // there is no per-day total to compute here.
                $totalDisplay.removeClass("visible").html("");
                $errorDisplay.removeClass("visible");
                return;
            }

            $pricingItems.removeClass("active");
            if (numDays > 0) {
                $pricingItems.filter('[data-tier-days="' + numDays + '"]').addClass("active");
            }

            var selectedEntries = [];
            checkedBoxes.each(function () {
                var $row = $(this).closest(".day-checkbox");
                var $countInput = $row.find(".day-count-input");
                var count = $countInput.length ? parseInt($countInput.val(), 10) || 1 : 1;
                selectedEntries.push({ date: $(this).val(), count: count });
            });

            var total = calculateLayeredPrice(selectedEntries, tiers, dayPrices);

            var fullPrice = 0;
            checkedBoxes.each(function () {
                var date = $(this).val();
                var price = dayPrices[date] || 0;
                var $row = $(this).closest(".day-checkbox");
                var $countInput = $row.find(".day-count-input");
                var count = $countInput.length ? parseInt($countInput.val(), 10) || 1 : 1;
                fullPrice += price * count;
            });

            var hasDiscount = numDays > 0 && total < fullPrice;

            if (numDays === 0) {
                $totalDisplay.removeClass("visible").html("");
            } else if (hasDiscount) {
                $totalDisplay.addClass("visible").html(
                    '<span class="day-selection-discount">' +
                    numDays + " " + daysLabel + " — " +
                    '<span class="day-selection-original-price">' + formatPrice(fullPrice) + "</span> " +
                    "<strong>" + formatPrice(total) + "</strong>" +
                    "</span>"
                );
            } else {
                $totalDisplay.addClass("visible").html(
                    totalLabel + ": <strong>" + formatPrice(total) + "</strong>"
                );
            }

            $errorDisplay.removeClass("visible");
        }

        $wrapper.off("click.daysel").on("click.daysel", ".day-count-minus, .day-count-plus", function (e) {
            e.preventDefault();
            var $input = $(this).siblings(".day-count-input");
            var val = parseInt($input.val(), 10) || 1;
            var max = parseInt($input.attr("max"), 10) || 99;

            if ($(this).hasClass("day-count-minus")) {
                if (val > 1) $input.val(val - 1);
            } else {
                if (val < max) $input.val(val + 1);
            }

            update();
            $("body").trigger("update_checkout");
        });

        $checkboxes.off("change.daysel").on("change.daysel", function () {
            update();
            $("body").trigger("update_checkout");
        });

        update();
    }

    function initializeAll() {
        $(".day-selection").each(function () {
            bindDaySelection($(this));
        });
    }

    // Initialize participants count dropdown
    function initializeParticipantsCount() {
        var $select = $("#participants_count");
        if (!$select.length) {
            return;
        }
        $select.on("change", function () {
            $("body").trigger("update_checkout");
        });
    }

    // Re-bind after WC re-renders the checkout (selectors are inside the
    // billing form, which gets replaced by WC's ajax on update).
    $(document.body).on("updated_checkout", initializeAll);

    initializeAll();
    initializeParticipantsCount();
});
