(function($) {
    'use strict';

    function initNews($scope) {
        const $widget = $scope.find('[data-widget="news"]');
        if ( !$widget.length ) return;

        const track = $widget.find('.news__track')[0];
        const prev  = $widget.find('.news__arrow--prev')[0];
        const nav   = $widget.find('.news__nav')[0];
        const next  = $widget.find('.news__arrow--next')[0];
        if ( !track ) return;

        let busy = false;

        const metrics = function() {
            const card  = track.querySelector('.news__card');
            const gap   = parseFloat(getComputedStyle(track).columnGap) || 0;
            const unit  = card ? card.offsetWidth + gap : track.clientWidth;
            const total = track.children.length;
            const perPage = Math.max(1, Math.floor((track.clientWidth + gap) / unit + 0.05));
            return { unit: unit, total: total, max: track.scrollWidth - track.clientWidth, step: Math.max(1, Math.min(perPage, total - perPage)) };
        };

        const jump = function(left) {
            track.style.scrollBehavior = 'auto';
            track.style.scrollSnapType = 'none';
            track.scrollLeft = left;
            void track.offsetWidth;
            track.style.scrollSnapType = '';
            track.style.scrollBehavior = '';
        };

        const update = function() {
            if ( nav ) nav.classList.toggle('is-hidden', track.scrollWidth - track.clientWidth <= 2);
        };

        const go = function(dir) {
            if ( busy ) return;
            const m = metrics();
            if ( m.max <= 2 ) return;
            busy = true;

            const dist = m.step * m.unit;
            const pos  = track.scrollLeft;

            if ( dir > 0 && pos + dist > m.max + 2 ) {
                const k = Math.ceil((pos + dist - m.max - 2) / m.unit);
                for ( let i = 0; i < k; i++ ) track.appendChild(track.firstElementChild);
                jump(pos - k * m.unit);
            } else if ( dir < 0 && pos - dist < -2 ) {
                const k = Math.ceil((dist - pos - 2) / m.unit);
                for ( let i = 0; i < k; i++ ) track.insertBefore(track.lastElementChild, track.firstElementChild);
                jump(pos + k * m.unit);
            }

            track.scrollBy({ left: dir * dist, behavior: 'smooth' });

            let done = false;
            const release = function() { if ( done ) return; done = true; busy = false; };
            if ( 'onscrollend' in window ) track.addEventListener('scrollend', release, { once: true });
            setTimeout(release, 700);
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
