/**
 * The checkout event picker: city, then theme and date, and the cart item is
 * switched to whatever the second select ends on.
 *
 * One city holds several products - several themes, each on its own date - so one
 * flat list would be long and would repeat the city on every row. The city select
 * only narrows the second one down; nothing is switched until a theme-and-date is
 * picked, because that is what a product is.
 *
 * The second select's row is hidden until the city has an answer, so the visitor
 * is never offered a list that means nothing yet; the server renders both rows so
 * the picker survives without this script, and gate() below takes the unreached
 * one off the page on init. Another product means another date, capacity and
 * price, so nothing of the old choice carries over: the switch answers with the
 * parts of the checkout it changed, already rendered, and they are swapped in
 * place - a reload is only what a reply missing any of them falls back to.
 *
 * Nothing is selected when the page loads, not even what the cart holds, because
 * putting a product in the cart is not the same as choosing where to go. PHP
 * refuses the order too (ProductGroup::requireChoice) - the guard here only saves
 * the visitor the round trip.
 */
(function ($) {
    'use strict';

    var wrapper = document.getElementById('alttag-product-group');
    if (!wrapper) {
        return;
    }

    var citySelect = document.getElementById('alttag-product-group-city');
    var eventSelect = document.getElementById('alttag-product-group-event');
    if (!citySelect || !eventSelect) {
        return;
    }

    // The row the second select sits in, so it can be taken off the page until
    // the city exists. No id of its own is needed: the row is the .form-row
    // wrapper PHP prints around each select, and it is also what
    // express-checkout-guard.js reads a required field out of.
    var eventRow = eventSelect.closest('.alttag-product-group__row');
    var GATED = 'alttag-product-group__row--gated';

    var config = window.alttagProductGroup || {};
    // Cities and their events - the whole group, built by
    // ProductGroup::groupTree() so the options built here and the ones the page
    // was rendered with cannot drift apart.
    var cities = config.cities || [];
    var errorBox = document.getElementById('alttag-product-group-error');
    var missingMessage = errorBox ? errorBox.textContent.trim() : '';

    // What the server rendered as picked: '' on the first visit, which is what a
    // failed switch has to put the selects back to.
    var picked = eventSelect.value;

    function showError(message) {
        wrapper.classList.add('alttag-product-group--invalid');
        if (errorBox) {
            // Revealed before the text is written: role="alert" announces a change
            // inside a live region, and a hidden region is not live yet.
            errorBox.hidden = false;
            errorBox.textContent = message || missingMessage;
        }
    }

    function clearError() {
        wrapper.classList.remove('alttag-product-group--invalid');
        if (errorBox) {
            errorBox.hidden = true;
        }
    }

    /**
     * Show or hide one level of the cascade.
     *
     * The select is disabled along with the row so a hidden level is out of the
     * tab order for keyboard users too, and aria-hidden keeps it out of the
     * screen reader's reading of the form; the express-checkout guard skips
     * hidden wrappers by design, and a hidden level always has an unanswered
     * visible one above it, so nothing stops being guarded.
     */
    function gateRow(row, select, open) {
        if (row) {
            row.classList.toggle(GATED, !open);
            if (open) {
                row.removeAttribute('aria-hidden');
            } else {
                row.setAttribute('aria-hidden', 'true');
            }
        }
        select.disabled = !open;
    }

    /**
     * One question more than there are answers: the theme-and-date row appears
     * with a city and goes away again when the city goes back to its
     * placeholder. Read off the current value, so this is also what opens the
     * cascade for a remembered pick on init.
     */
    function gate() {
        gateRow(eventRow, eventSelect, !!citySelect.value);
    }

    /** Both selects are one field to the visitor, so they go busy together. */
    function setBusy(busy) {
        wrapper.classList.toggle('alttag-product-group--busy', busy);
        citySelect.disabled = busy;
        eventSelect.disabled = busy;
        if (!busy) {
            // Leaving the busy state re-enabled both; the cascade says which of
            // them the visitor may actually answer.
            gate();
        }
    }

    /** Send the visitor to the first select they have not answered yet. */
    function focusPicker() {
        var target = citySelect.value ? eventSelect : citySelect;
        if (typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
        try {
            target.focus({preventScroll: true});
        } catch (e) {
            target.focus();
        }
    }

    /** The events of one city, empty for no city and for a city off the list. */
    function eventsOf(city) {
        for (var i = 0; i < cities.length; i++) {
            if (cities[i].city === city) {
                return cities[i].events || [];
            }
        }

        return [];
    }

    /**
     * Refill one select with the options of the answer above it.
     *
     * The placeholder that is already in the select is kept rather than rebuilt,
     * so its wording stays the one PHP rendered; the localized fallback is only
     * for the case where the markup arrived without one. Labels are written with
     * textContent, never innerHTML, because they are shop content.
     *
     * A select with nothing above it answered is left empty and disabled: that is
     * the whole point of the cascade, and an empty value is what the submit guard
     * and PHP both read as "not picked".
     */
    function fill(select, options, enabled, placeholderText) {
        var placeholder = select.options[0];

        select.innerHTML = '';
        if (placeholder) {
            select.appendChild(placeholder);
        } else {
            select.appendChild(new Option(placeholderText || '', ''));
        }

        if (enabled) {
            options.forEach(function (option) {
                var element = document.createElement('option');
                element.value = option.id;
                element.textContent = option.label;
                element.disabled = !option.available;
                select.appendChild(element);
            });
        }

        select.value = '';
        select.disabled = !enabled;
    }

    function fillEvents(city) {
        fill(eventSelect, eventsOf(city), !!city, config.eventPlaceholder);
    }

    /** The city one event id belongs to, '' for no pick and for an unknown id. */
    function cityOf(eventId) {
        for (var i = 0; i < cities.length; i++) {
            var events = cities[i].events || [];
            for (var j = 0; j < events.length; j++) {
                if (String(events[j].id) === String(eventId)) {
                    return cities[i].city;
                }
            }
        }

        return '';
    }

    /**
     * Put both selects back to what the cart really holds after a refused
     * switch, so the picker never claims something the cart does not have.
     *
     * The city too, not only the theme-and-date: the refused pick was made in
     * some other city, and leaving that city standing would offer a list of
     * dates none of which is the one in the cart.
     */
    function restore() {
        setBusy(false);
        var city = cityOf(picked);
        citySelect.value = city;
        fillEvents(city);
        eventSelect.value = picked;
        gate();
        // The switch covered the express wallets on its way out
        // (alttag_checkout_updating); nothing changed after all, so uncover
        // them now instead of leaving the guard's 8s fail-safe to do it.
        $(document.body).trigger('updated_checkout');
    }

    /**
     * The nodes the switch sends back, keyed by what they replace. Missing one
     * means the page and the answer disagree about what the checkout looks like,
     * and then only a reload can be trusted - see applyFragments().
     */
    var FRAGMENTS = ['#alttag-selection-ui', '.woocommerce-checkout-review-order-table'];

    /**
     * Swap the rendered parts of the checkout for the ones the new product
     * produced. Returns false when anything expected is missing, on either side,
     * which is the caller's cue to reload instead of patching half a page.
     */
    function applyFragments(fragments) {
        if (!fragments) {
            return false;
        }

        for (var i = 0; i < FRAGMENTS.length; i++) {
            var selector = FRAGMENTS[i];
            if (typeof fragments[selector] !== 'string' || !$(selector).length) {
                return false;
            }
        }

        FRAGMENTS.forEach(function (selector) {
            $(selector).replaceWith(fragments[selector]);
        });

        return true;
    }

    citySelect.addEventListener('change', function () {
        clearError();
        // Deliberately not carrying the old answer over: a theme-and-date
        // belongs to the city it was picked in.
        fillEvents(citySelect.value);
        gate();
    });

    if (config.ajaxUrl) {
        eventSelect.addEventListener('change', function () {
            if (!eventSelect.value) {
                return;
            }

            clearError();
            setBusy(true);
            // Covers the express wallets for the whole switch
            // (express-checkout-guard.js listens for it): between here and the
            // new fragments the cart holds one product and the page shows
            // another, and Apple Pay opened in that window would pay for the
            // wrong date. The guard uncovers them again on updated_checkout.
            $(document.body).trigger('alttag_checkout_updating');

            var body = new URLSearchParams({
                action: 'alttag_switch_group_product',
                product_id: eventSelect.value,
                nonce: wrapper.getAttribute('data-nonce')
            });

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (result) {
                if (result && result.success) {
                    // Picking what the cart already holds moves nothing, so there
                    // is nothing to reload - the option is simply picked now.
                    if (result.data && result.data.changed === false) {
                        picked = eventSelect.value;
                        setBusy(false);
                        return;
                    }

                    // Everything the switch changed arrives rendered, so the
                    // page is patched instead of fetched again. A reply that
                    // does not carry all of it is not something to patch a
                    // checkout with - reload and let PHP render the truth.
                    if (!applyFragments(result.data && result.data.fragments)) {
                        window.location.reload();
                        return;
                    }

                    if (result.data.cities) {
                        cities = result.data.cities;
                        config.cities = cities;
                    }
                    if (result.data.selectionConfigs) {
                        window.selectionConfigs = result.data.selectionConfigs;
                    }
                    config.cartHash = result.data.cartHash;

                    // The pick stands, and the selects go back to answerable.
                    picked = eventSelect.value;
                    setBusy(false);

                    // The selection scripts and Stripe all re-read the checkout
                    // on this event, which is also what tells the express guard
                    // the switch is over and the wallets may be uncovered.
                    $(document.body).trigger('updated_checkout');
                    return;
                }
                restore();
                showError(result && result.data ? result.data.message : '');
            }).catch(function () {
                restore();
                showError(config.failedMessage);
            });
        });
    }

    // The server renders both rows visible, which is what the picker is without
    // this script. From here on the row the visitor has not reached yet is
    // hidden, starting with the state the page arrived in.
    gate();

    /**
     * WooCommerce asks before it submits: wc_checkout_form.submit() runs
     * `$form.triggerHandler('checkout_place_order')` and gives up when a handler
     * returns false (woocommerce/assets/js/frontend/checkout.js). triggerHandler
     * does not bubble, so this has to sit on the form itself and cannot be
     * delegated from document.body. stopImmediatePropagation keeps a gateway
     * handler bound after this one from overwriting the false with its own
     * return value, which is what jQuery hands back to WooCommerce.
     */
    $(function () {
        $('form.checkout').on('checkout_place_order', function (event) {
            if (eventSelect.value) {
                clearError();
                return;
            }

            event.stopImmediatePropagation();
            event.preventDefault();
            showError();
            focusPicker();

            return false;
        });
    });
}(jQuery));
