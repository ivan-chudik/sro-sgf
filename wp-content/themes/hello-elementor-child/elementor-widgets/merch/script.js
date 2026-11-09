(function($) {
    'use strict';

    function initMerch($scope) {
        const $widget = $scope.find('[data-widget="merch"]');
        if ( !$widget.length ) return;

        $widget.find('[data-merch-cta]').off('click.xnMerch').on('click.xnMerch', function() {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ event: 'merch_form_click', link_url: this.href });
        });
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/merch.default',
            initMerch
        );
    });

})(jQuery);
