(function($) {
    'use strict';

    function initHero($scope) {
        const $widget = $scope.find('[data-widget="hero"]');
        if ( !$widget.length ) return;

        const cd = $widget.find('[data-countdown]')[0];
        if ( !cd ) return;

        const target = new Date(cd.getAttribute('data-countdown')).getTime();
        if ( isNaN(target) ) return;

        const tick = function() {
            cd.textContent = Math.max(0, Math.ceil((target - Date.now()) / 864e5));
        };
        tick();
        clearInterval(cd._xnTimer);
        cd._xnTimer = setInterval(tick, 6e4);
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/hero.default',
            initHero
        );
    });

})(jQuery);
