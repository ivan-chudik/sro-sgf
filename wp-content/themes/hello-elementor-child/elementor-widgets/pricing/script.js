(function($) {
    'use strict';

    function setMode($widget, mode) {
        $widget.attr('data-mode', mode);
        $widget.find('.pricing__tab[data-mode]').each(function() {
            const on = this.getAttribute('data-mode') === mode;
            this.classList.toggle('is-on', on);
            this.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function initParallax($widget) {
        const section = $widget[0];
        const els = Array.prototype.slice.call(section.querySelectorAll('.pricing__prx'));
        if ( !els.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches ) return;

        let raf = 0;
        const update = function() {
            raf = 0;
            const r = section.getBoundingClientRect();
            const mid = r.top + r.height / 2 - window.innerHeight / 2;
            els.forEach(function(el) {
                const s = parseFloat(el.getAttribute('data-speed')) || 0;
                const flip = el.hasAttribute('data-flip') ? 'scaleX(-1) ' : '';
                el.style.transform = flip + 'translateY(' + (-mid * s).toFixed(1) + 'px)';
            });
        };

        if (section._xnParallax) window.removeEventListener('scroll', section._xnParallax);
        section._xnParallax = function() { if ( !raf ) raf = window.requestAnimationFrame(update); };
        window.addEventListener('scroll', section._xnParallax, { passive: true });
        update();
    }

    function initPricing($scope) {
        const $widget = $scope.find('[data-widget="pricing"]');
        if ( !$widget.length ) return;

        $widget.off('click.xnPricing').on('click.xnPricing', '.pricing__tab[data-mode]', function() {
            setMode($widget, this.getAttribute('data-mode'));
        });

        initParallax($widget);
    }

    // CTA kdekoľvek na stránke (napr. hero): <a href="#vstupenky" data-pricing-mode="live|stream">
    $(document).off('click.xnPricingMode').on('click.xnPricingMode', '[data-pricing-mode]', function() {
        const mode = this.getAttribute('data-pricing-mode');
        $('[data-widget="pricing"]').each(function() { setMode($(this), mode); });
    });

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/pricing.default',
            initPricing
        );
    });

})(jQuery);
