(function($) {
    'use strict';

    function initReasons($scope) {
        const $widget = $scope.find('[data-widget="reasons"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/reasons.default',
            initReasons
        );
    });

})(jQuery);
