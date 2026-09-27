(function($) {
    'use strict';

    function initStickyCta($scope) {
        const $widget = $scope.find('[data-widget="sticky-cta"]');
        if ( !$widget.length ) return;
        // bez JS logiky — anchor scroll s offsetom headera rieši header/script.js
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/sticky-cta.default',
            initStickyCta
        );
    });

})(jQuery);
