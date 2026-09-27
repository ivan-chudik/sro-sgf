(function($) {
    'use strict';

    function initFaq($scope) {
        const $widget = $scope.find('[data-widget="faq"]');
        if ( !$widget.length ) return;
        // bez JS logiky — natívny <details> akordeón
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/faq.default',
            initFaq
        );
    });

})(jQuery);
