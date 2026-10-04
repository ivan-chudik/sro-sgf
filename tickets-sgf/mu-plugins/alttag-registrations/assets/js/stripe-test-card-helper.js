/**
 * Click-to-copy for the Stripe test card helper box.
 * Reads its translated "Copied" label from the alttagStripeTestCard config
 * localized by StripeTestCardHelper::enqueueAssets().
 */
(function () {
    if (window.__stchBound) {
        return;
    }
    window.__stchBound = true;

    function copiedLabel() {
        var cfg = window.alttagStripeTestCard;
        return (cfg && cfg.i18n && cfg.i18n.copied) || 'Copied';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.stripe-test-card-helper .stch-copy');
        if (!btn) {
            return;
        }
        e.preventDefault();
        var value = btn.getAttribute('data-stch-copy') || '';
        var done = function () {
            var original = btn.dataset.stchLabel || btn.textContent;
            btn.dataset.stchLabel = original;
            btn.textContent = copiedLabel();
            btn.classList.add('is-copied');
            setTimeout(function () {
                btn.textContent = original;
                btn.classList.remove('is-copied');
            }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done, function () {
                fallbackCopy(value, done);
            });
        } else {
            fallbackCopy(value, done);
        }
    }, false);

    function fallbackCopy(value, onDone) {
        var ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        onDone();
    }
})();
