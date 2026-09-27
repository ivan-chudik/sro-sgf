(function($) {
    'use strict';

    function initSchedule($scope) {
        const $widget = $scope.find('[data-widget="schedule"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/schedule.default',
            initSchedule
        );
    });

})(jQuery);
