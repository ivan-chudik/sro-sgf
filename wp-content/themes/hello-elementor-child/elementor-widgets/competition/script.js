(function($) {
    'use strict';

    function initCompetition($scope) {
        const $widget = $scope.find('[data-widget="competition"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/competition.default',
            initCompetition
        );
    });

})(jQuery);
