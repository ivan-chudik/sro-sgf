/**
 * Block Stripe Express Checkout (Apple Pay / Google Pay / Payment Request)
 * from opening the wallet sheet when the WooCommerce checkout form isn't
 * filled in or the selected party no longer fits its seating wave.
 *
 * The wallet buttons are Stripe Elements rendered inside a cross-origin
 * iframe — their click handler runs entirely inside Stripe's JS and isn't
 * routed through any prototype method exposed on `window.wc_stripe.*`, so
 * we can't gate the wallet via `is_valid_checkout` or similar hooks.
 *
 * Workaround: lay a same-origin overlay div over each wallet button. It
 * catches the click in our own DOM layer before the iframe ever sees it.
 * The overlay disappears only after the form is valid and the server confirms
 * current grouped-seat capacity, letting later clicks reach Stripe.
 */
(function ($) {
    'use strict';

    // Match every rendering woo-stripe-payment ships:
    //   - Legacy dash-cased buttons  → .wc-stripe-<gateway>-button
    //   - Newer underscore-in-name   → .wc-stripe_<gateway>-checkout-button
    //   - Payment Request unified    → .wc-stripe-payment-request-button
    // v4 renders the Express Checkout Element directly in a
    // `.banner_payment_method_stripe_*` list item; older releases use the
    // button selectors retained below.
    var SELECTORS = [
        '.wc-stripe-payment-request-button',
        '.wc-stripe-applepay-button',
        '.wc-stripe-googlepay-button',
        '.wc-stripe_applepay-checkout-button',
        '.wc-stripe_googlepay-checkout-button',
        '.wc-stripe_payment_request-checkout-button',
        '.banner_payment_method_stripe_applepay',
        '.banner_payment_method_stripe_googlepay',
        '.banner_payment_method_stripe_payment_request',
        '#wc-stripe_applepay-checkout-button',
        '#wc-stripe_googlepay-checkout-button',
        '#wc-stripe_payment_request-checkout-button',
        // v4+ renders the Express Checkout Element into dedicated containers
        // (woo-stripe templates/checkout/applepay.php). Without these the
        // guard never matched anything on this site and was dead code.
        '#wc-stripe-applepay-container',
        '#wc-stripe-googlepay-container',
        '#wc-stripe-payment-request-container'
    ].join(', ');

    var capacityValid = true;
    var capacityChecking = false;
    var capacityMessage = '';
    var capacityTimer = null;
    var capacityRequest = null;
    var capacitySequence = 0;
    var checkoutUpdating = false;
    var checkoutFailSafe = null;

    function hasCapacityCheck() {
        var cfg = window.alttagExpressCapacity;
        return !!(cfg && cfg.ajaxUrl && cfg.action && cfg.nonce);
    }

    function getNoticeText() {
        if (capacityMessage) {
            return capacityMessage;
        }
        if (
            typeof window.alttagExpressGuard !== 'undefined'
            && window.alttagExpressGuard
            && window.alttagExpressGuard.noticeText
        ) {
            return window.alttagExpressGuard.noticeText;
        }
        return 'Please fill in all required checkout fields first.';
    }

    /**
     * Returns true when every required checkout field has a value. Combines
     * three checks:
     *
     *   1. HTML5 `form.checkValidity()` — covers any input that carries the
     *      native `required` attribute (rare in WC core, common in our
     *      FieldBuilder fields).
     *
     *   2. WC's `.validate-required` wrapper convention — WooCommerce does
     *      not emit the HTML5 `required` attribute on its native billing
     *      inputs, only `aria-required="true"` plus a wrapper class. Read
     *      the underlying input / select / radio group ourselves.
     *
     *   3. GDPR consent checkbox — separately gated so the alttag plugin's
     *      consent flow can't slip through even if its wrapper happens to
     *      lack `.validate-required`.
     *
     * Hidden wrappers are skipped (a field can be required only when shown).
     */
    function isFormValid() {
        var form = document.querySelector('form.woocommerce-checkout, form.checkout');
        if (!form) {
            return true;
        }

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return false;
        }

        var wrappers = form.querySelectorAll('.validate-required');
        for (var i = 0; i < wrappers.length; i++) {
            if (!isWrapperFilled(wrappers[i])) {
                return false;
            }
        }

        var gdpr = document.querySelector('input[name="gdpr_consent"]');
        if (gdpr && gdpr.offsetParent !== null && !gdpr.checked) {
            return false;
        }

        if (!areBusinessFieldsValid()) {
            return false;
        }

        return true;
    }

    /**
     * When the buyer ticks "Buy as Business client" (SF plugin's
     * `#wi_as_company` checkbox), the company-invoicing block becomes
     * mandatory but the individual fields DON'T carry `required` or
     * `.validate-required` — SF enforces the requirement on submit via
     * option checks, not DOM annotation. So the loops above see them as
     * "always valid" and Google Pay / Apple Pay open with an empty Tax ID.
     *
     * Explicit list from PHP (via `alttagExpressGuard.businessRequiredFields`,
     * filterable server-side) so downstream projects using non-SF
     * invoicing plugins can point us at their own DOM IDs.
     */
    function areBusinessFieldsValid() {
        var cfg = window.alttagExpressGuard;
        if (!cfg || !cfg.businessRequiredFields || !cfg.businessRequiredFields.length) {
            return true;
        }
        var toggleId = cfg.businessToggleId || 'wi_as_company';
        var toggle = document.getElementById(toggleId);
        if (!toggle || !toggle.checked || toggle.offsetParent === null) {
            return true;
        }
        for (var i = 0; i < cfg.businessRequiredFields.length; i++) {
            var field = document.getElementById(cfg.businessRequiredFields[i]);
            if (!field || field.offsetParent === null) {
                continue;
            }
            var value = (field.value || '').trim();
            if (value === '') {
                return false;
            }
        }
        return true;
    }

    function isWrapperFilled(wrapper) {
        if (wrapper.offsetParent === null) {
            return true; // hidden wrappers don't count
        }
        var field = wrapper.querySelector('input, select, textarea');
        if (!field) {
            return true;
        }
        if (field.type === 'checkbox') {
            return !!wrapper.querySelector('input[type="checkbox"]:checked');
        }
        if (field.type === 'radio') {
            return !!wrapper.querySelector('input[type="radio"]:checked');
        }
        return !!field.value && field.value.trim() !== '';
    }

    function firstEmptyRequiredField(form) {
        var wrappers = form.querySelectorAll('.validate-required');
        for (var i = 0; i < wrappers.length; i++) {
            if (!isWrapperFilled(wrappers[i])) {
                return wrappers[i].querySelector('input, select, textarea');
            }
        }
        return null;
    }

    function showErrors() {
        var form = document.querySelector('form.woocommerce-checkout, form.checkout');
        if (!form) {
            return;
        }

        // HTML5 reportValidity surfaces native required-attribute violations
        // (FieldBuilder fields), if any.
        if (typeof form.reportValidity === 'function') {
            form.reportValidity();
        }

        // For WC's `.validate-required` fields without a native `required`
        // attribute, scroll to and focus the first empty one — WC itself
        // adds the `.woocommerce-invalid` class on blur which renders the
        // red border, so the user gets a visual cue once they tab away.
        var target = form.querySelector(':invalid') || firstEmptyRequiredField(form);
        if (!target) {
            var gdpr = document.querySelector('input[name="gdpr_consent"]');
            if (gdpr && !gdpr.checked) {
                target = gdpr;
            }
        }
        if (target) {
            if (typeof target.scrollIntoView === 'function') {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            if (typeof target.focus === 'function') {
                try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
            }
        }
    }

    function ensureOverlay(btn) {
        if (btn._alttagGuardActive) {
            var currentText = getNoticeText();
            btn._alttagOverlay.title = currentText;
            btn._alttagNotice.textContent = currentText;
            return;
        }
        btn._alttagGuardActive = true;

        if (window.getComputedStyle(btn).position === 'static') {
            btn.style.position = 'relative';
        }
        btn._alttagOriginalOpacity = btn.style.opacity || '';
        btn.style.opacity = '0.55';
        btn.style.transition = 'opacity 0.2s ease';

        var noticeText = getNoticeText();

        var overlay = document.createElement('div');
        overlay.className = 'alttag-wallet-guard';
        // Fully covering, hit-testable overlay. `pointer-events:auto` is
        // the default, but stating it explicitly guards against ancestor
        // rules that set pointer-events:none. rgba(0,0,0,0.001) is
        // effectively invisible while still counting as a paint layer,
        // which some browsers require to route clicks to a div covering
        // a cross-origin iframe (Stripe Express Checkout Element renders
        // Google Pay / Apple Pay inside a js.stripe.com iframe — the
        // overlay has to reliably eat clicks before they descend into
        // the iframe's own DOM).
        overlay.style.cssText = 'position:absolute;inset:0;z-index:2147483647;'
            + 'cursor:not-allowed;background:rgba(0,0,0,0.001);'
            + 'pointer-events:auto;';
        overlay.title = noticeText;
        overlay.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            if (isFormValid()) {
                refresh();
            } else {
                showErrors();
            }
        }, true);
        btn.appendChild(overlay);
        btn._alttagOverlay = overlay;

        var notice = document.createElement('div');
        notice.className = 'alttag-wallet-guard-notice';
        notice.textContent = noticeText;
        notice.style.cssText = 'margin-top:6px;font-size:13px;'
            + 'color:#b91c1c;font-family:inherit;text-align:center;';
        btn.parentNode.insertBefore(notice, btn.nextSibling);
        btn._alttagNotice = notice;
    }

    function removeOverlay(btn) {
        if (!btn._alttagGuardActive) {
            return;
        }
        btn._alttagGuardActive = false;
        btn.style.opacity = btn._alttagOriginalOpacity || '';
        if (btn._alttagOverlay) {
            btn._alttagOverlay.remove();
            btn._alttagOverlay = null;
        }
        if (btn._alttagNotice) {
            btn._alttagNotice.remove();
            btn._alttagNotice = null;
        }
    }

    /**
     * Stripe renders each wallet (Apple Pay / Google Pay) in its own button
     * div with an inner iframe — but the outer div carries `min-height:40px`,
     * so `offsetParent` and `getBoundingClientRect()` on it still report a
     * tall element even when Stripe has collapsed the wallet because the
     * device doesn't support it. Inspect the iframe's own size instead, it
     * collapses to ~8px when the wallet is unavailable.
     */
    function isButtonActuallyVisible(btn) {
        if (btn.offsetParent === null) {
            return false;
        }
        var iframe = btn.querySelector('iframe');
        if (!iframe) {
            // No iframe yet — Stripe may still be mounting. Treat as visible
            // so we don't briefly flash the wallet through; refresh() runs
            // again on input/change/updated_checkout and self-corrects.
            return true;
        }
        return iframe.getBoundingClientRect().height > 20;
    }

    function refresh() {
        var valid = !checkoutUpdating && isFormValid() && (!hasCapacityCheck() || (capacityValid && !capacityChecking));
        document.querySelectorAll(SELECTORS).forEach(function (btn) {
            if (!isButtonActuallyVisible(btn)) {
                removeOverlay(btn);
                return;
            }
            if (valid) {
                removeOverlay(btn);
            } else {
                ensureOverlay(btn);
            }
        });
    }

    /** Keep the wallet covered until the current party/slot fits right now. */
    function scheduleCapacityCheck() {
        if (!hasCapacityCheck()) {
            return;
        }

        // On the checkout page the checkout form carries the data; on the
        // product page (wallet button sits next to add-to-cart) and cart
        // page the product/cart form holds the selection inputs instead.
        var form = document.querySelector('form.woocommerce-checkout, form.checkout')
            || document.querySelector('form.cart')
            || document.querySelector('form.woocommerce-cart-form');
        if (!form) {
            return;
        }

        capacityValid = false;
        capacityChecking = true;
        capacityMessage = '';
        var sequence = ++capacitySequence;
        refresh();
        clearTimeout(capacityTimer);
        if (capacityRequest && typeof capacityRequest.abort === 'function') {
            capacityRequest.abort();
            capacityRequest = null;
        }

        capacityTimer = setTimeout(function () {
            var cfg = window.alttagExpressCapacity;
            capacityRequest = $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: cfg.action,
                    nonce: cfg.nonce,
                    post_data: $(form).serialize()
                }
            }).done(function (response) {
                if (sequence !== capacitySequence) {
                    return;
                }
                var data = response && response.data ? response.data : {};
                capacityValid = !!(response && response.success && data.valid);
                capacityMessage = capacityValid ? '' : (data.message || cfg.requestError || '');
            }).fail(function (xhr, status) {
                if (sequence !== capacitySequence || status === 'abort') {
                    return;
                }
                capacityValid = false;
                capacityMessage = cfg.requestError || '';
            }).always(function () {
                if (sequence === capacitySequence) {
                    capacityChecking = false;
                    refresh();
                }
            });
        }, 150);
    }

    /**
     * Woo-Stripe Payment fires `checkout_place_order_<gateway_id>` events on
     * the checkout form when the user hits the (Stripe-branded) Place Order
     * button — Google Pay, Apple Pay, Payment Request, cards, etc. Its own
     * `is_valid_checkout()` in wc-stripe.js is a minimal `[name="terms"]`
     * check that misses our SF business-invoicing fields, GDPR consent, and
     * every other required WC billing field.
     *
     * Returning `false` from a jQuery event handler cancels the event, which
     * woo-stripe's own handler (`checkout_place_order` above) relies on to
     * decide whether to proceed. Bind to the express-pay gateways we care
     * about and abort the click when `isFormValid()` says the form is
     * incomplete. This is the ONLY reliable interception point because the
     * wallet sheet opens directly from the click — before WC's server-side
     * `woocommerce_checkout_process` action fires — so we can't stop it
     * with PHP validation once the click has landed.
     */
    var WALLET_GATEWAY_EVENTS = [
        'checkout_place_order_stripe_googlepay',
        'checkout_place_order_stripe_applepay',
        'checkout_place_order_stripe_payment_request'
    ].join(' ');

    $(function () {
        refresh();
        scheduleCapacityCheck();
        document.body.addEventListener('input', refresh, true);
        document.body.addEventListener('change', function () {
            refresh();
            scheduleCapacityCheck();
        }, true);
        $(document.body).on('click', '.participant-type-minus, .participant-type-plus', function () {
            setTimeout(scheduleCapacityCheck, 0);
        });
        $(document.body).on('updated_checkout', function () {
            refresh();
            scheduleCapacityCheck();
        });

        // Quantity changes are debounced in selection-participant-types.js;
        // cover the wallets from the FIRST click until update_checkout AJAX
        // finishes. Fail-safe clears after 8s in case WC never fires
        // updated_checkout (failed update).
        $(document.body).on('alttag_checkout_updating update_checkout', function () {
            checkoutUpdating = true;
            clearTimeout(checkoutFailSafe);
            checkoutFailSafe = setTimeout(function () {
                checkoutUpdating = false;
                refresh();
            }, 8000);
            refresh();
        });
        $(document.body).on('updated_checkout checkout_error', function () {
            clearTimeout(checkoutFailSafe);
            // Stripe re-initializes wallet data on updated_checkout; give it
            // a beat before uncovering the buttons.
            setTimeout(function () {
                checkoutUpdating = false;
                refresh();
            }, 500);
        });

        // Bind to the form. Delegated via document.body so the handler
        // survives WC's AJAX rebuilds of the review-order fragment (which
        // replace form-internal DOM without re-firing form-level handlers).
        $(document.body).on(WALLET_GATEWAY_EVENTS, 'form.woocommerce-checkout, form.checkout', function (e) {
            if (!isFormValid()) {
                e.stopImmediatePropagation();
                e.preventDefault();
                showErrors();
                return false;
            }
        });

        // Stripe mounts the wallet iframes asynchronously and only resolves
        // the final per-wallet visibility (collapsing unsupported wallets
        // down to ~8px) after the iframe boots. Re-evaluate for a few
        // seconds after page load so we don't leave the overlay on a
        // wallet Stripe has already decided to hide.
        if (typeof ResizeObserver !== 'undefined') {
            var ro = new ResizeObserver(refresh);
            document.querySelectorAll(SELECTORS).forEach(function (btn) {
                var iframe = btn.querySelector('iframe');
                if (iframe) {
                    ro.observe(iframe);
                }
            });
        }
        var pollTicks = 0;
        var poll = setInterval(function () {
            refresh();
            if (++pollTicks > 20) {
                clearInterval(poll);
            }
        }, 250);
    });
})(jQuery);
