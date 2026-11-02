(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest || !e.target.closest('a.cmplz-show-banner')) return;
        e.preventDefault();
        var btn = document.querySelector('#cmplz-manage-consent .cmplz-manage-consent');
        if (btn) {
            btn.click();
        } else if (typeof window.cmplz_set_banner_status === 'function') {
            window.cmplz_set_banner_status('show');
        }
    });

    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('.cmplz-cookiebanner a[href], #cmplz-document a[href]') : null;
        if (!a) return;
        try {
            if (new URL(a.href, location.href).host !== location.host) {
                a.target = '_blank';
                a.rel = 'noopener';
            }
        } catch (err) {}
    }, true);
})();
