(function($) {
    'use strict';

    function initNews($scope) {
        const $widget = $scope.find('[data-widget="news"]');
        if ( !$widget.length ) return;

        const track = $widget.find('.news__track')[0];
        const prev  = $widget.find('.news__arrow--prev')[0];
        const next  = $widget.find('.news__arrow--next')[0];
        const nav   = $widget.find('.news__nav')[0];
        if ( !track ) return;

        const step = function() {
            const card = track.querySelector('.news__card');
            if ( !card ) return track.clientWidth;
            const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
            const perPage = Math.max(1, Math.floor((track.clientWidth + gap) / (card.offsetWidth + gap)));
            return perPage * (card.offsetWidth + gap);
        };

        const update = function() {
            const max = track.scrollWidth - track.clientWidth;
            if ( nav ) nav.classList.toggle('is-hidden', max <= 2);
        };

        const go = function(dir) {
            const max = track.scrollWidth - track.clientWidth;
            if ( dir > 0 && track.scrollLeft >= max - 2 ) return track.scrollTo({ left: 0, behavior: 'smooth' });
            if ( dir < 0 && track.scrollLeft <= 2 ) return track.scrollTo({ left: max, behavior: 'smooth' });
            track.scrollBy({ left: dir * step(), behavior: 'smooth' });
        };

        if ( prev ) prev.onclick = function() { go(-1); };
        if ( next ) next.onclick = function() { go(1); };

        if ( 'ResizeObserver' in window ) new ResizeObserver(update).observe(track);
        else window.addEventListener('resize', update);
        update();
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/news.default',
            initNews
        );
    });

})(jQuery);
