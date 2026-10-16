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
            if ( prev ) prev.disabled = track.scrollLeft <= 2;
            if ( next ) next.disabled = track.scrollLeft >= max - 2;
        };

        if ( prev ) prev.onclick = function() { track.scrollBy({ left: -step(), behavior: 'smooth' }); };
        if ( next ) next.onclick = function() { track.scrollBy({ left: step(), behavior: 'smooth' }); };

        track.addEventListener('scroll', function() { window.requestAnimationFrame(update); }, { passive: true });
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
