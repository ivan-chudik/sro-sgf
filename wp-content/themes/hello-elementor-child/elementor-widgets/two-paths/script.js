(function($) {
    'use strict';

    function initTwoPaths($scope) {
        const $widget = $scope.find('[data-widget="two-paths"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/two-paths.default',
            initTwoPaths
        );
    });

})(jQuery);
