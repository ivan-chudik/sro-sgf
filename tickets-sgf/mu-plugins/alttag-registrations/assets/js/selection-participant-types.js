jQuery(function ($) {
    "use strict";

    function initializeParticipantTypes() {
        // The selection UI (#alttag-selection-ui contains
        // #participant-types-wrapper) is swapped wholesale by
        // checkout-product-group.js when the customer changes locality, theme
        // or slot, and window.selectionConfigs is re-assigned in the same
        // swap. Caching either across calls left the +/- steppers bound to a
        // detached node (dead until a manual refresh). Look everything up on
        // demand instead.
        function currentConfig() {
            return (typeof window.selectionConfigs !== "undefined"
                && window.selectionConfigs
                && window.selectionConfigs.participant_types)
                ? window.selectionConfigs.participant_types
                : null;
        }

        function $w() {
            return $("#participant-types-wrapper");
        }

        function formatPrice(amount) {
            var currency = (currentConfig() || {}).currency || "";
            return amount.toFixed(2).replace(".", ",") + " " + currency;
        }

        function hasMatrixTiers() {
            var typeTiers = (currentConfig() || {}).type_tiers || {};
            for (var k in typeTiers) {
                if (Object.prototype.hasOwnProperty.call(typeTiers, k)
                    && typeTiers[k] && Object.keys(typeTiers[k]).length > 0) {
                    return true;
                }
            }
            return false;
        }

        function selectedDayCount() {
            var $days = $(".day-checkbox input:checked");
            return $days.length;
        }

        // Resolve effective unit price for a participant type given the current
        // day selection. If the type defines tiers, pick the largest tier
        // whose day-count is ≤ selected days (so 2-day price applies for 2+ days).
        // Falls back to base price when tiers don't apply.
        function effectivePrice(type) {
            var config = currentConfig() || {};
            var prices = config.prices || {};
            var typeTiers = config.type_tiers || {};
            var basePrice = parseFloat(prices[type]) || 0;
            var tiers = typeTiers[type];
            if (!tiers) return basePrice;

            var dayCount = selectedDayCount();
            if (dayCount === 0) return basePrice;

            var best = null;
            var bestPrice = null;
            for (var k in tiers) {
                if (!Object.prototype.hasOwnProperty.call(tiers, k)) continue;
                var d = parseInt(k, 10);
                var p = parseFloat(tiers[k]);
                if (d <= dayCount && (best === null || d > best)) {
                    best = d;
                    bestPrice = p;
                }
            }
            return bestPrice !== null ? bestPrice : basePrice;
        }

        function rowLimits($row) {
            return {
                min: parseInt($row.data("min"), 10) || 0,
                max: parseInt($row.data("max"), 10) || 0
            };
        }

        function clamp(count, limits) {
            if (count < limits.min) count = limits.min;
            if (limits.max > 0 && count > limits.max) count = limits.max;
            if (count < 0) count = 0;
            return count;
        }

        function updateAll() {
            var config = currentConfig();
            if (!config) {
                return;
            }
            var $wrapper = $w();
            if (!$wrapper.length) {
                return;
            }

            var $totalDisplay = $wrapper.find(".participant-types-total");
            var $errorDisplay = $wrapper.find(".participant-types-error");
            var prices = config.prices || {};
            var pricesBefore = config.prices_before || {};
            var perPersonLabel = config.per_person_label || "person";
            var totalLabel = config.total_label || "Total";
            var originalPriceLabel = config.original_price_label || "Original price";
            var tierDiscountLabel = config.tier_discount_label || "Discount";
            var discountLabelTpl = config.discount_label || "Discount (%d days)";

            var total = 0;
            var originalTotal = 0;
            var totalPersons = 0;
            var breakdown = [];
            var showPerPerson = hasMatrixTiers();
            var dayCount = selectedDayCount();

            $wrapper.find(".participant-type-row").each(function () {
                var $row = $(this);
                var type = $row.data("type");
                var limits = rowLimits($row);
                var $input = $row.find(".participant-type-count-input");
                var $hidden = $row.find(".participant-type-selected");
                var $minus = $row.find(".participant-type-minus");
                var $plus = $row.find(".participant-type-plus");

                var count = clamp(parseInt($input.val(), 10) || 0, limits);
                $input.val(count);

                var selected = count > 0;
                $row.toggleClass("checked", selected);

                if (selected) {
                    $hidden.prop("disabled", false);
                } else {
                    $hidden.prop("disabled", true);
                }

                $minus.prop("disabled", count <= limits.min);
                $plus.prop("disabled", limits.max > 0 && count >= limits.max);

                var price = effectivePrice(type);
                if (price > 0) {
                    var priceText = formatPrice(price);
                    if (showPerPerson) {
                        priceText += " / " + perPersonLabel;
                    }
                    $row.find(".participant-type-price").html(priceText);
                }
                var subtotal = price * count;
                total += subtotal;
                totalPersons += count;

                // Original price = base unit × selected day count × participant count.
                // Lets us surface "you saved X" when the multi-day tier kicks in.
                // Price before any discount: the pricing tier's original price when
                // there is one, otherwise the plain base price.
                var basePrice = parseFloat(pricesBefore[type]) || parseFloat(prices[type]) || 0;
                var effectiveDays = dayCount > 0 ? dayCount : 1;
                originalTotal += basePrice * effectiveDays * count;

                if (count > 0 && price > 0) {
                    breakdown.push({
                        label: $row.find(".participant-type-label").text(),
                        count: count,
                        subtotal: subtotal
                    });
                }
            });

            if (totalPersons === 0) {
                $totalDisplay.removeClass("visible").html("");
            } else {
                var html = "";
                if (breakdown.length > 0) {
                    breakdown.forEach(function (item) {
                        html += '<div class="participant-types-total-line">'
                            + '<span>' + item.count + '× ' + item.label + '</span>'
                            + '<span>' + formatPrice(item.subtotal) + '</span>'
                            + '</div>';
                    });
                }

                var savings = originalTotal - total;
                if (savings > 0.005) {
                    // Multi-day discount names the day count, a pricing tier names
                    // itself (e.g. "Early bird discount").
                    var discountLabel = dayCount > 0
                        ? discountLabelTpl.replace("%d", dayCount)
                        : tierDiscountLabel;
                    html += '<div class="participant-types-total-line participant-types-total-line--muted">'
                        + '<span>' + originalPriceLabel + '</span>'
                        + '<span>' + formatPrice(originalTotal) + '</span>'
                        + '</div>';
                    html += '<div class="participant-types-total-line participant-types-total-line--savings">'
                        + '<span>' + discountLabel + '</span>'
                        + '<span>−' + formatPrice(savings) + '</span>'
                        + '</div>';
                }

                html += '<div class="participant-types-total-line participant-types-total-line--total">'
                    + '<strong>' + totalLabel + '</strong>'
                    + '<strong>' + formatPrice(total) + '</strong>'
                    + '</div>';
                $totalDisplay.addClass("visible").html(html);
            }

            $errorDisplay.removeClass("visible");
        }

        // Debounce checkout updates: one burst of +/- clicks must produce a
        // single update_checkout AJAX. Express wallets stay covered from the
        // FIRST click until the request completes (express-checkout-guard.js
        // listens on alttag_checkout_updating), so Apple Pay can never open
        // with a stale cart snapshot.
        var checkoutUpdateTimer = null;
        var checkoutUpdateInFlight = false;
        var resubmitCheckout = false;
        var resubmitFailSafe = null;

        function scheduleCheckoutUpdate() {
            $(document.body).trigger("alttag_checkout_updating");
            checkoutUpdateInFlight = true;
            clearTimeout(checkoutUpdateTimer);
            checkoutUpdateTimer = setTimeout(function () {
                checkoutUpdateTimer = null;
                $("body").trigger("update_checkout");
            }, 350);
        }

        // While an update is pending or in flight the server session still
        // holds the PREVIOUS participant counts while the UI shows the new
        // ones. An order placed in that window would be priced from the stale
        // session. Block the submit, flush the update now, re-submit once
        // updated_checkout confirms the server caught up.
        $(document.body).on("updated_checkout", function () {
            clearTimeout(resubmitFailSafe);
            setTimeout(function () {
                checkoutUpdateInFlight = false;
                if (resubmitCheckout) {
                    resubmitCheckout = false;
                    $("form.checkout").trigger("submit");
                }
            }, 500);
        });
        $(document.body).on("checkout_error", function () {
            setTimeout(function () {
                checkoutUpdateInFlight = false;
                resubmitCheckout = false;
            }, 500);
        });

        function bindCheckoutSubmitFlush() {
            $("form.checkout").on("checkout_place_order", function (event) {
                if (!checkoutUpdateTimer && !checkoutUpdateInFlight) {
                    return;
                }
                resubmitCheckout = true;
                if (checkoutUpdateTimer) {
                    clearTimeout(checkoutUpdateTimer);
                    checkoutUpdateTimer = null;
                    $("body").trigger("update_checkout");
                }
                resubmitFailSafe = setTimeout(function () {
                    checkoutUpdateInFlight = false;
                    resubmitCheckout = false;
                }, 8000);
                event.stopImmediatePropagation();
                event.preventDefault();
                return false;
            });
        }
        $(bindCheckoutSubmitFlush);

        // Reload (F5) inside the debounce window would drop the new counts:
        // the timer never fires, the session keeps the old values and the
        // reloaded page shows a different count/sum than the UI had. Post a
        // real update_order_review request (same shape WC's checkout.js sends:
        // security nonce + post_data as a serialized form string) with
        // keepalive so it completes during unload.
        $(window).on("beforeunload", function () {
            if (!checkoutUpdateTimer || typeof wc_checkout_params === "undefined") {
                return;
            }
            try {
                var url = wc_checkout_params.wc_ajax_url.replace("%%endpoint%%", "update_order_review");
                var body = "security=" + encodeURIComponent(wc_checkout_params.update_order_review_nonce) +
                    "&post_data=" + encodeURIComponent($("form.checkout").serialize()) +
                    "&payment_method=" + encodeURIComponent($("input[name=payment_method]:checked").val() || "") +
                    "&country=" + encodeURIComponent($("#billing_country").val() || "");
                fetch(url, {
                    method: "POST",
                    credentials: "same-origin",
                    keepalive: true,
                    headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
                    body: body
                });
            } catch (e) { /* best effort */ }
        });

        // Bind on document, not on the wrapper: checkout-product-group.js
        // replaces #alttag-selection-ui (and with it the wrapper) on every
        // locality/theme/slot change, which strips a wrapper-scoped binding
        // and leaves the steppers dead until a manual page refresh.
        $(document).off("click.parttype").on("click.parttype", ".participant-type-minus, .participant-type-plus", function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.prop("disabled")) return;

            var $row = $btn.closest(".participant-type-row");
            var limits = rowLimits($row);
            var $input = $btn.siblings(".participant-type-count-input");
            var val = parseInt($input.val(), 10) || 0;

            if ($btn.hasClass("participant-type-minus")) {
                val = clamp(val - 1, limits);
            } else {
                val = clamp(val + 1, limits);
            }

            $input.val(val);
            updateAll();
            scheduleCheckoutUpdate();
        });

        $(document.body).on("updated_checkout", function () {
            updateAll();
        });

        // Day-selection changes shift tier prices for participant types.
        $(document).on("change.parttype", ".day-checkbox input[type=checkbox]", function () {
            updateAll();
        });

        updateAll();
    }

    initializeParticipantTypes();
});
