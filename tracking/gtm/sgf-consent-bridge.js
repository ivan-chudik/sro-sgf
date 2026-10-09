const setDefaultConsentState = require('setDefaultConsentState');
const updateConsentState = require('updateConsentState');
const getCookieValues = require('getCookieValues');
const setCookie = require('setCookie');
const createQueue = require('createQueue');
const gtagSet = require('gtagSet');
const templateStorage = require('templateStorage');

const SHARED = 'sgf_ev_consent';
const dataLayerPush = createQueue('dataLayer');

const first = (name) => {
  const values = getCookieValues(name);
  return values && values.length ? values[0] : undefined;
};

const readComplianz = () => {
  const st = first('cmplz_statistics');
  const mk = first('cmplz_marketing');
  const decided = first('cmplz_banner-status') === 'dismissed' || st === 'allow' || mk === 'allow';
  if (!decided) return undefined;
  return { a: st === 'allow', m: mk === 'allow' };
};

const readShared = () => {
  const v = first(SHARED);
  if (!v || v.length !== 4 || v.substring(0, 1) !== 'a' || v.substring(2, 3) !== 'm') return undefined;
  return { a: v.substring(1, 2) === '1', m: v.substring(3, 4) === '1' };
};

const writeShared = (s) => {
  setCookie(SHARED, 'a' + (s.a ? '1' : '0') + 'm' + (s.m ? '1' : '0'), {
    domain: 'auto',
    path: '/',
    'max-age': 31536000,
    samesite: 'Lax',
    secure: true
  }, true);
};

const same = (x, y) => !!x && !!y && x.a === y.a && x.m === y.m;

const toConsent = (s) => {
  const m = s.m ? 'granted' : 'denied';
  return {
    analytics_storage: s.a ? 'granted' : 'denied',
    ad_storage: m,
    ad_user_data: m,
    ad_personalization: m
  };
};

const cur = templateStorage.getItem('cur');

if (!cur) {
  setDefaultConsentState({
    analytics_storage: 'denied',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    functionality_storage: 'granted',
    security_storage: 'granted',
    wait_for_update: 500
  });
  gtagSet('ads_data_redaction', true);

  const local = readComplianz();
  const shared = readShared();
  const start = local || shared || { a: false, m: false };
  if (local && !same(local, shared)) writeShared(local);
  if (start.a || start.m) updateConsentState(toConsent(start));

  templateStorage.setItem('cur', start);
  templateStorage.setItem('firedA', start.a);
  templateStorage.setItem('firedM', start.m);
} else {
  const next = readComplianz();
  if (next) {
    if (!same(next, readShared())) writeShared(next);
    if (!same(next, cur)) {
      updateConsentState(toConsent(next));
      templateStorage.setItem('cur', next);
      dataLayerPush({
        event: 'sgf_consent_update',
        sgf_consent_analytics: next.a ? 'granted' : 'denied',
        sgf_consent_ads: next.m ? 'granted' : 'denied'
      });
      if (next.a && !templateStorage.getItem('firedA')) {
        templateStorage.setItem('firedA', true);
        dataLayerPush({ event: 'sgf_consent_analytics_granted' });
      }
      if (next.m && !templateStorage.getItem('firedM')) {
        templateStorage.setItem('firedM', true);
        dataLayerPush({ event: 'sgf_consent_ads_granted' });
      }
    }
  }
}

data.gtmOnSuccess();
