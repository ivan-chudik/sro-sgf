(function($) {
    'use strict';

    function initFacts($scope) {
        const $widget = $scope.find('[data-widget="facts"]');
        if ( !$widget.length ) return;
        // bez JS logiky — hover efekty sú čisté CSS
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/facts.default',
            initFacts
        );
    });

})(jQuery);
