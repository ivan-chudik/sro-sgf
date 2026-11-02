(function($) {
    'use strict';

    function initVideo($scope) {
        const $widget = $scope.find('[data-widget="video"]');
        if ( !$widget.length ) return;

        $widget.find('.video__poster[data-fallback]').each(function() {
            const img = this;
            const check = function() {
                if ( img.naturalWidth && img.naturalWidth <= 120 ) {
                    img.src = img.getAttribute('data-fallback');
                    img.removeAttribute('data-fallback');
                }
            };
            if ( img.complete ) check(); else img.addEventListener('load', check, { once: true });
            img.addEventListener('error', function() {
                if ( img.hasAttribute('data-fallback') ) {
                    img.src = img.getAttribute('data-fallback');
                    img.removeAttribute('data-fallback');
                }
            }, { once: true });
        });

        $widget.find('.video__trigger').off('click.xnVideo').on('click.xnVideo', function() {
            const frame = this.closest('.video__frame');
            const id = frame.getAttribute('data-video-id');
            if ( !id ) return;
            const iframe = document.createElement('iframe');
            iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1&rel=0&playsinline=1';
            iframe.title = frame.getAttribute('data-video-title') || 'YouTube video';
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
            iframe.allowFullscreen = true;
            iframe.referrerPolicy = 'strict-origin-when-cross-origin';
            frame.classList.add('is-playing');
            this.replaceWith(iframe);
            iframe.focus();
        });
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/video.default',
            initVideo
        );
    });

})(jQuery);
