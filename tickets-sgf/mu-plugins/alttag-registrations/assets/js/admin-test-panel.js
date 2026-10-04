/**
 * Slide-in side panel for the per-user testing toggles.
 *
 * The admin bar node "Test modes" is the entry point; clicking it opens the
 * panel rendered in the footer by AdminTestToggles::renderPanel(). Each switch
 * posts to the very same admin-post.php endpoint (and nonce) as the no-script
 * fallback links, only asking for a JSON reply.
 *
 * Labels come from the alttagTestPanel config localized by
 * AdminTestToggles::enqueueAssets().
 */
(function () {
    if (window.__alttagTestPanelBound) {
        return;
    }
    window.__alttagTestPanelBound = true;

    function i18n(key, fallback) {
        var cfg = window.alttagTestPanel;
        return (cfg && cfg.i18n && cfg.i18n[key]) || fallback;
    }

    var panel = null;
    var backdrop = null;
    var trigger = null;

    function refs() {
        if (!panel) {
            panel = document.getElementById('alttag-test-panel');
            backdrop = document.getElementById('alttag-test-panel-backdrop');
            trigger = document.querySelector('#wp-admin-bar-alttag-test > a, #wp-admin-bar-alttag-test > .ab-empty-item');
        }
        return panel;
    }

    function open() {
        if (!refs()) {
            return;
        }
        panel.classList.add('is-open');
        if (backdrop) {
            backdrop.classList.add('is-open');
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');
        }
        /*
         * Move focus to the panel itself, never to the close button: focusing a
         * button programmatically right after a click makes the browser treat it
         * as keyboard focus, so :focus-visible sticks a ring on it for as long as
         * the panel is open. The container carries tabindex="-1" for this.
         * Escape is bound on document, so it keeps working either way.
         */
        panel.focus({ preventScroll: true });
    }

    function close() {
        if (!refs() || !panel.classList.contains('is-open')) {
            return;
        }
        panel.classList.remove('is-open');
        if (backdrop) {
            backdrop.classList.remove('is-open');
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
            trigger.focus();
        }
    }

    /**
     * Mirror the "any toggle on" dot in the admin bar node title.
     */
    function syncDot() {
        var dot = document.querySelector('#wp-admin-bar-alttag-test .alttag-test-dot');
        if (!dot || !refs()) {
            return;
        }
        var anyOn = !!panel.querySelector('.atp-switch[aria-checked="true"]');
        dot.setAttribute('data-on', anyOn ? '1' : '0');
    }

    function syncRow(btn, on) {
        btn.setAttribute('aria-checked', on ? 'true' : 'false');

        var row = btn.closest('.atp-row');
        if (row) {
            row.classList.toggle('is-on', on);
        }

        var warningId = btn.getAttribute('data-warning');
        if (warningId) {
            var warning = document.getElementById(warningId);
            if (warning) {
                warning.hidden = !on;
            }
        }

        syncDot();
    }

    function toggle(btn) {
        if (btn.classList.contains('is-busy')) {
            return;
        }
        var endpoint = btn.getAttribute('data-endpoint');
        if (!endpoint) {
            return;
        }
        btn.classList.add('is-busy');

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!payload || !payload.success || !payload.data) {
                throw new Error('unexpected response');
            }
            syncRow(btn, payload.data.new_state === '1');
        }).catch(function () {
            window.alert(i18n('error', 'Could not change the toggle. Please try again.'));
        }).then(function () {
            btn.classList.remove('is-busy');
        });
    }

    /*
     * aria-modal="true" promises assistive tech that focus stays inside the
     * dialog, so Tab has to wrap around while the panel is open.
     */
    function trapTab(e) {
        if (!panel || !panel.classList.contains('is-open')) {
            return;
        }
        var focusables = panel.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (!focusables.length) {
            return;
        }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        var current = document.activeElement;

        if (!panel.contains(current)) {
            e.preventDefault();
            first.focus();
        } else if (e.shiftKey && (current === first || current === panel)) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && current === last) {
            e.preventDefault();
            first.focus();
        }
    }

    document.addEventListener('click', function (e) {
        var entry = e.target.closest('#wp-admin-bar-alttag-test > a, #wp-admin-bar-alttag-test > .ab-empty-item');
        if (entry) {
            e.preventDefault();
            if (refs() && panel.classList.contains('is-open')) {
                close();
            } else {
                open();
            }
            return;
        }

        if (e.target.closest('#alttag-test-panel-backdrop')) {
            close();
            return;
        }

        if (e.target.closest('#alttag-test-panel .atp-close')) {
            e.preventDefault();
            close();
            return;
        }

        var btn = e.target.closest('#alttag-test-panel .atp-switch');
        if (btn) {
            e.preventDefault();
            toggle(btn);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            close();
            return;
        }

        if (e.key === 'Tab') {
            trapTab(e);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (!refs() || !trigger) {
            return;
        }
        trigger.setAttribute('aria-controls', 'alttag-test-panel');
        trigger.setAttribute('aria-expanded', 'false');
    });
}());
