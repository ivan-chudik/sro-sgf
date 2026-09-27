(function($) {
    'use strict';

    function initTicker($scope) {
        const $widget = $scope.find('[data-widget="ticker"]');
        if ( !$widget.length ) return;
        // bez JS logiky — marquee je čisté CSS (4× zopakovaná sada, posun o -50 %)
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/ticker.default',
            initTicker
        );
    });

})(jQuery);
