(function($) {
    'use strict';

    function initMaintenance($scope) {
        const $widget = $scope.find('[data-widget="maintenance"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/maintenance.default',
            initMaintenance
        );
    });

})(jQuery);
