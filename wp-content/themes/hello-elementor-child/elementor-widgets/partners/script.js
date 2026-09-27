(function($) {
    'use strict';

    function initPartners($scope) {
        const $widget = $scope.find('[data-widget="partners"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/partners.default',
            initPartners
        );
    });

})(jQuery);
