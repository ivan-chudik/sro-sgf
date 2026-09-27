(function($) {
    'use strict';

    function initVenue($scope) {
        const $widget = $scope.find('[data-widget="venue"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/venue.default',
            initVenue
        );
    });

})(jQuery);
