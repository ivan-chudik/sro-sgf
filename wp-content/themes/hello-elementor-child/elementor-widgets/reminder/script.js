(function($) {
    'use strict';

    function initReminder($scope) {
        const $widget = $scope.find('[data-widget="reminder"]');
        if ( !$widget.length ) return;
        // bez JS logiky — odoslanie rieši natívny Elementor Form v šablóne
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/reminder.default',
            initReminder
        );
    });

})(jQuery);
