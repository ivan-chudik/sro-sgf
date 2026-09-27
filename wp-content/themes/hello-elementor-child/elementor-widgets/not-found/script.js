(function($) {
    'use strict';

    function initNotFound($scope) {
        const $widget = $scope.find('[data-widget="not-found"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/not-found.default',
            initNotFound
        );
    });

})(jQuery);
