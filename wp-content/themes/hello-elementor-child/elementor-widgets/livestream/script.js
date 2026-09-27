(function($) {
    'use strict';

    function initLivestream($scope) {
        const $widget = $scope.find('[data-widget="livestream"]');
        if ( !$widget.length ) return;
        // bez JS logiky — mockup je čisté CSS
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/livestream.default',
            initLivestream
        );
    });

})(jQuery);
