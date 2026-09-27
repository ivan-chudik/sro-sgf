(function($) {
    'use strict';

    function barHeight() {
        return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-bar-h')) || 74;
    }

    function setBarHeight() {
        var bar = document.querySelector('.custom-header .header__bar');
        if (bar) document.documentElement.style.setProperty('--header-bar-h', bar.offsetHeight + 'px');
    }

    function scrollToHashTarget(hash, behavior) {
        if (!hash || hash === '#') return;
        var target;
        try { target = document.querySelector(decodeURIComponent(hash)); } catch (err) { return; }
        if (!target) return;
        var y = target.getBoundingClientRect().top + window.pageYOffset - barHeight() - 8;
        window.scrollTo({ top: Math.max(0, y), behavior: behavior || 'auto' });
    }

    function initHeader($scope) {
        const $widget = $scope.find('[data-widget="header"]');
        if ( !$widget.length ) return;

        setBarHeight();

        $widget.off('click.xnHeader').on('click.xnHeader', '.elementor-nav-menu--dropdown a', function() {
            $widget.find('.elementor-menu-toggle.elementor-active').trigger('click');
        });
    }

    if (!window.xnHeaderBound) {
        window.xnHeaderBound = true;

        window.addEventListener('resize', setBarHeight);
        window.addEventListener('load', setBarHeight);
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(setBarHeight);

        document.addEventListener('click', function(e) {
            if (document.body.classList.contains('elementor-editor-active')) return;
            var a = e.target.closest('a[href]');
            if (!a) return;
            var url;
            try { url = new URL(a.getAttribute('href'), window.location.href); } catch (err) { return; }
            if (!url.hash || url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
            if (!document.getElementById(decodeURIComponent(url.hash.slice(1)))) return;
            e.preventDefault();
            history.pushState(null, '', url.hash);
            scrollToHashTarget(url.hash, 'smooth');
        });

        if (window.location.hash) {
            window.addEventListener('load', function() {
                setTimeout(function() { scrollToHashTarget(window.location.hash); }, 300);
            });
        }
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/header.default',
            initHeader
        );
    });

})(jQuery);
