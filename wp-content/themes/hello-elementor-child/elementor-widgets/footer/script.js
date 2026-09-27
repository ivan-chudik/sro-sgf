(function($) {
    'use strict';

    function initFooter($scope) {
        const $widget = $scope.find('[data-widget="footer"]');
        if ( !$widget.length ) return;
        // bez JS logiky
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/footer.default',
            initFooter
        );
    });

})(jQuery);
