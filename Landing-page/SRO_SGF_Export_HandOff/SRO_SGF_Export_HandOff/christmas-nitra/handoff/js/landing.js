/* CHRISTMAS NITRA landing — behaviour. Load once before </body>. Every part is guarded, so it also works when only some blocks are on the page. */
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.tabs button'));
  var plans = document.getElementById('plans'), program = document.getElementById('program');
  function setMode(m) {
    tabs.forEach(function (x) { var on = x.getAttribute('data-mode') === m; x.classList.toggle('on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
    if (plans) plans.setAttribute('data-mode', m);
    if (program) program.setAttribute('data-mode', m);
  }
  tabs.forEach(function (b) { b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); }); });
  // hero CTAs: <a href="#program" data-tab="live|stream"> — scrolls to pricing and pre-selects the tab
  Array.prototype.forEach.call(document.querySelectorAll('a[data-tab]'), function (a) { a.addEventListener('click', function () { setMode(a.getAttribute('data-tab')); }); });

  // parallax ribbons behind the pricing block
  if (program) {
    var els = Array.prototype.slice.call(program.querySelectorAll('.prx'));
    if (els.length && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      var raf = 0;
      var update = function () {
        raf = 0; var r = program.getBoundingClientRect(); var mid = r.top + r.height / 2 - window.innerHeight / 2;
        els.forEach(function (el) { var s = parseFloat(el.getAttribute('data-speed')) || 0; var flip = el.classList.contains('b') ? 'scaleX(-1) ' : ''; el.style.transform = flip + 'translateY(' + (-mid * s).toFixed(1) + 'px)'; });
      };
      window.addEventListener('scroll', function () { if (!raf) raf = window.requestAnimationFrame(update); }, { passive: true });
      update();
    }
  }

  // countdown (days) to the first competition day — 26 Nov 2026, 09:00 CET
  var cd = document.getElementById('cd-d');
  if (cd) {
    var target = new Date('2026-11-26T09:00:00+01:00').getTime();
    var tick = function () { cd.textContent = Math.max(0, Math.ceil((target - Date.now()) / 864e5)); };
    tick(); window.setInterval(tick, 6e4);
  }
})();
