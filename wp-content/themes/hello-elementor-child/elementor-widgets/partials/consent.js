(function () {
  var w = window, d = document;
  if (w.__sgfConsent) return;
  w.__sgfConsent = true;
  w.dataLayer = w.dataLayer || [];
  function gtag() { w.dataLayer.push(arguments); }

  var SHARED = 'sgf_ev_consent';
  var DOMAIN = /(^|\.)sgf\.sk$/.test(location.hostname) ? '; domain=.sgf.sk' : '';

  function getCookie(name) {
    var m = d.cookie.match(new RegExp('(?:^|; )' + name.replace(/[-.]/g, '\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function readComplianz() {
    var st = getCookie('cmplz_statistics');
    var mk = getCookie('cmplz_marketing');
    var decided = getCookie('cmplz_banner-status') === 'dismissed' || st === 'allow' || mk === 'allow';
    return decided ? { a: st === 'allow', m: mk === 'allow' } : null;
  }

  function readShared() {
    var m = (getCookie(SHARED) || '').match(/^a([01])m([01])$/);
    return m ? { a: m[1] === '1', m: m[2] === '1' } : null;
  }

  function writeShared(s) {
    d.cookie = SHARED + '=a' + (s.a ? 1 : 0) + 'm' + (s.m ? 1 : 0) +
      '; path=/; max-age=31536000' + DOMAIN + '; SameSite=Lax' +
      (location.protocol === 'https:' ? '; Secure' : '');
  }

  function same(x, y) { return !!x && !!y && x.a === y.a && x.m === y.m; }

  function toConsent(s) {
    var m = s.m ? 'granted' : 'denied';
    return { analytics_storage: s.a ? 'granted' : 'denied', ad_storage: m, ad_user_data: m, ad_personalization: m };
  }

  var local = readComplianz();
  var shared = readShared();
  var cur = local || shared || { a: false, m: false };
  var fired = { a: cur.a, m: cur.m };
  if (local && !same(local, shared)) writeShared(local);

  var defaults = toConsent(cur);
  defaults.functionality_storage = 'granted';
  defaults.security_storage = 'granted';
  defaults.wait_for_update = 500;
  gtag('consent', 'default', defaults);
  gtag('set', 'ads_data_redaction', true);

  function sync() {
    var next = readComplianz();
    if (!next) return;
    if (!same(next, readShared())) writeShared(next);
    if (same(next, cur)) return;
    cur = next;
    gtag('consent', 'update', toConsent(next));
    w.dataLayer.push({
      event: 'sgf_consent_update',
      sgf_consent_analytics: next.a ? 'granted' : 'denied',
      sgf_consent_ads: next.m ? 'granted' : 'denied'
    });
    if (next.a && !fired.a) { fired.a = true; w.dataLayer.push({ event: 'sgf_consent_analytics_granted' }); }
    if (next.m && !fired.m) { fired.m = true; w.dataLayer.push({ event: 'sgf_consent_ads_granted' }); }
  }

  ['cmplz_fire_categories', 'cmplz_status_change', 'cmplz_revoke', 'cmplz_enable_category'].forEach(function (e) {
    d.addEventListener(e, function () { setTimeout(sync, 50); });
  });
  d.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('.cmplz-cookiebanner, .cmplz-manage-consent-container, #cmplz-manage-consent, .cmplz-btn')) {
      setTimeout(sync, 300);
    }
  }, true);
})();
