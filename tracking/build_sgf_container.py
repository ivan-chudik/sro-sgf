#!/usr/bin/env python3
"""
Z exportu GTM-KKRD2SZH (workspace9) vyrobí kontajner pre sro.sgf.sk + tickets.sgf.sk:
basic Consent Mode v2 s Complianz free bridge, zdieľaný súhlas cez cookie na .sgf.sk,
Meta tagy viazané na ad_storage, eventy zo sro.sgf.sk.

Spustenie:  python3 -I tracking/build_sgf_container.py
Výstup:     tracking/GTM-KKRD2SZH_sro-tickets.json  (import: Overwrite do nového workspace)
"""
import json
import pathlib

HERE = pathlib.Path(__file__).resolve().parent
SRC = HERE / 'GTM-KKRD2SZH_workspace9.json'
OUT = HERE / 'GTM-KKRD2SZH_sro-tickets.json'

ALL_PAGES = '2147479553'
CONSENT_INIT = '2147479572'

data = json.loads(SRC.read_text(encoding='utf-8'))
cv = data['containerVersion']
ACC, CON = cv['accountId'], cv['containerId']
FP = '1791500000000'

ids = {
    'tag': max(int(t['tagId']) for t in cv['tag']),
    'trigger': max(int(t['triggerId']) for t in cv['trigger']),
    'variable': max(int(v['variableId']) for v in cv['variable']),
}


def next_id(kind):
    ids[kind] += 1
    return str(ids[kind])


def tpl(key, value):
    return {'type': 'TEMPLATE', 'key': key, 'value': value}


def boolean(key, value):
    return {'type': 'BOOLEAN', 'key': key, 'value': 'true' if value else 'false'}


def eq(arg0, arg1, kind='EQUALS', negate=False):
    params = [{'type': 'TEMPLATE', 'key': 'arg0', 'value': arg0},
              {'type': 'TEMPLATE', 'key': 'arg1', 'value': arg1}]
    if negate:
        params.append({'type': 'BOOLEAN', 'key': 'negate', 'value': 'true'})
    return {'type': kind, 'parameter': params}


def needs(*types):
    return {
        'consentStatus': 'NEEDED',
        'consentType': {'type': 'LIST', 'list': [{'type': 'TEMPLATE', 'value': t} for t in types]},
    }


def by_name(items, name):
    for item in items:
        if item['name'] == name:
            return item
    raise KeyError(name)


def param(obj, key):
    for p in obj['parameter']:
        if p['key'] == key:
            return p
    raise KeyError(key)


def add_tag(name, type_, parameter, triggers, consent, **extra):
    tag = {
        'accountId': ACC, 'containerId': CON, 'tagId': next_id('tag'),
        'name': name, 'type': type_, 'parameter': parameter, 'fingerprint': FP,
        'firingTriggerId': triggers, 'tagFiringOption': 'ONCE_PER_EVENT',
        'monitoringMetadata': {'type': 'MAP'}, 'consentSettings': consent,
    }
    tag.update(extra)
    cv['tag'].append(tag)
    return tag


def add_trigger(name, type_, **fields):
    trig = {'accountId': ACC, 'containerId': CON, 'triggerId': next_id('trigger'),
            'name': name, 'type': type_, 'fingerprint': FP}
    trig.update(fields)
    cv['trigger'].append(trig)
    return trig['triggerId']


def custom_event_trigger(name, event, extra_filter=None):
    fields = {'customEventFilter': [eq('{{_event}}', event)]}
    if extra_filter:
        fields['filter'] = extra_filter
    return add_trigger(name, 'CUSTOM_EVENT', **fields)


def add_variable(name, type_, parameter):
    cv['variable'].append({'accountId': ACC, 'containerId': CON, 'variableId': next_id('variable'),
                           'name': name, 'type': type_, 'parameter': parameter,
                           'fingerprint': FP, 'formatValue': {}})


def html_tag_params(path):
    return [tpl('html', (HERE / 'gtm' / path).read_text(encoding='utf-8')), boolean('supportDocumentWrite', False)]


# ── 1. Odstrániť URL-parameter consent logiku a nepoužité Complianz premenné ──
REMOVE_TAGS = {'Consent Bridge', 'Tickets Links'}
REMOVE_TRIGGERS = {'Event - Consent Ready'}
REMOVE_VARS = {'URL - consent', 'Cookie - Marketing', 'Cookie - Statistics', 'Cookie - Functional'}
cv['tag'] = [t for t in cv['tag'] if t['name'] not in REMOVE_TAGS]
cv['trigger'] = [t for t in cv['trigger'] if t['name'] not in REMOVE_TRIGGERS]
cv['variable'] = [v for v in cv['variable'] if v['name'] not in REMOVE_VARS]

# ── 2. Premenné ──
param(by_name(cv['variable'], 'JS - sgf_source'), 'javascript')['value'] = (
    "function() {\n"
    "  var sub = {{Page Hostname}}.split('.')[0];\n"
    "  if (sub !== 'tickets') return sub;\n"
    "  var ref = {{URL - ref}};\n"
    "  try {\n"
    "    if (ref) { sessionStorage.setItem('sgf_ref', ref); return ref; }\n"
    "    return sessionStorage.getItem('sgf_ref') || 'direct';\n"
    "  } catch (e) { return ref || 'direct'; }\n"
    "}"
)
param(by_name(cv['variable'], 'JS - ticket_type'), 'javascript')['value'] = (
    "function() {\n"
    "  var el = {{Click Element}};\n"
    "  if (!el || !el.closest) return 'unknown';\n"
    "  if (el.closest('#gtm-btn-livestream, .only-stream')) return 'livestream';\n"
    "  if (el.closest('#gtm-btn-inperson, .only-live')) return 'in-person';\n"
    "  var sec = el.closest('[data-widget=\"pricing\"]');\n"
    "  if (sec) return sec.getAttribute('data-mode') === 'stream' ? 'livestream' : 'in-person';\n"
    "  return 'unknown';\n"
    "}"
)
add_variable('DLV - form_name', 'v', [tpl('dataLayerVersion', '2'), boolean('setDefaultValue', False), tpl('name', 'form_name')])
add_variable('DLV - form_lang', 'v', [tpl('dataLayerVersion', '2'), boolean('setDefaultValue', False), tpl('name', 'form_lang')])
add_variable('ADS-CONV-ID-SRO', 'c', [tpl('value', '850307814')])
add_variable('ADS-LABEL-SRO', 'c', [tpl('value', 'DOPLNIT')])

# ── 3. Triggery ──
just_events = by_name(cv['trigger'], 'Trigger - Just Events')['triggerId']
t_consent_a = custom_event_trigger('CE - Consent analytics granted', 'sgf_consent_analytics_granted')
t_consent_m = custom_event_trigger('CE - Consent ads granted', 'sgf_consent_ads_granted')
t_lead = custom_event_trigger('CE - Generate Lead', 'generate_lead')
t_purchase_sro = custom_event_trigger('Purchase - SRO', 'purchase', [eq('{{JS - sgf_source}}', 'sro')])
t_dom_events = add_trigger('DOM Ready - Just Events', 'DOM_READY',
                           filter=[eq('{{JS - sgf_site}}', 'tickets', negate=True)])
t_atc_sro = add_trigger(
    'Add To Cart - SRO', 'LINK_CLICK',
    filter=[eq('{{Page Hostname}}', 'sro.sgf.sk'), eq('{{Click URL}}', 'tickets.sgf.sk', 'CONTAINS')],
    waitForTags={'type': 'BOOLEAN', 'value': 'false'},
    checkValidation={'type': 'BOOLEAN', 'value': 'false'},
    waitForTagsTimeout={'type': 'TEMPLATE', 'value': '2000'},
    uniqueTriggerId={'type': 'TEMPLATE'},
)

# ── 4. Consent (basic): každý tag čaká na súhlas cez built-in consent checks ──
ANALYTICS = needs('analytics_storage')
ADS = needs('ad_storage')
consent_by_tag = {
    'GA Config': ANALYTICS,
    'GA4 - Add To Cart': ANALYTICS,
    'GA4 - Purchase': ANALYTICS,
    'GAds - Config Remarketing': ADS,
    'GAds - Conversion - Nákup SAO': ADS,
    'Conversion Linker': ADS,
    'Meta Pixel': ADS,
    'Meta - Add To Cart': ADS,
    'Meta - Purchase': ADS,
}
for name, consent in consent_by_tag.items():
    by_name(cv['tag'], name)['consentSettings'] = consent

# Page-level tagy sa dopália aj po kliknutí "Prijať" na tej istej stránke
by_name(cv['tag'], 'GA Config')['firingTriggerId'].append(t_consent_a)
for name in ('GAds - Config Remarketing', 'Conversion Linker', 'Meta Pixel'):
    by_name(cv['tag'], name)['firingTriggerId'].append(t_consent_m)

for name in ('GA4 - Add To Cart', 'Meta - Add To Cart'):
    by_name(cv['tag'], name)['firingTriggerId'].append(t_atc_sro)

# ── 5. Nové tagy ──
add_tag('Consent - Default + Complianz Bridge', 'html', html_tag_params('consent-bridge.html'),
        [CONSENT_INIT], {'consentStatus': 'NOT_NEEDED'},
        priority={'type': 'INTEGER', 'value': '999'})
add_tag('Tickets Links - ref', 'html', html_tag_params('tickets-links-ref.html'),
        [just_events], {'consentStatus': 'NOT_NEEDED'})
add_tag('Lead Listener - Elementor Forms', 'html', html_tag_params('lead-listener.html'),
        [t_dom_events], {'consentStatus': 'NOT_NEEDED'})

add_tag('GA4 - Generate Lead', 'gaawe', [
    boolean('sendEcommerceData', False),
    {'type': 'LIST', 'key': 'eventSettingsTable', 'list': [
        {'type': 'MAP', 'map': [tpl('parameter', 'form_name'), tpl('parameterValue', '{{DLV - form_name}}')]},
        {'type': 'MAP', 'map': [tpl('parameter', 'form_lang'), tpl('parameterValue', '{{DLV - form_lang}}')]},
        {'type': 'MAP', 'map': [tpl('parameter', 'event_source'), tpl('parameterValue', '{{JS - sgf_source}}')]},
    ]},
    tpl('eventName', 'generate_lead'),
    tpl('measurementIdOverride', '{{GA-ID}}'),
], [t_lead], ANALYTICS)

meta_lead = json.loads(json.dumps(by_name(cv['tag'], 'Meta - Add To Cart')))
param(meta_lead, 'standardEventName')['value'] = 'Lead'
param(meta_lead, 'objectPropertyList')['list'] = [
    {'type': 'MAP', 'map': [tpl('name', 'event_source'), tpl('value', '{{JS - sgf_source}}')]},
    {'type': 'MAP', 'map': [tpl('name', 'content_name'), tpl('value', '{{DLV - form_name}}')]},
]
add_tag('Meta - Lead', meta_lead['type'], meta_lead['parameter'], [t_lead], ADS)

sao = json.loads(json.dumps(by_name(cv['tag'], 'GAds - Conversion - Nákup SAO')))
param(sao, 'conversionId')['value'] = '{{ADS-CONV-ID-SRO}}'
param(sao, 'conversionLabel')['value'] = '{{ADS-LABEL-SRO}}'
add_tag('GAds - Conversion - Nákup SRO', 'awct', sao['parameter'], [t_purchase_sro], ADS, paused=True)

for obj in cv['tag'] + cv['trigger'] + cv['variable']:
    obj['fingerprint'] = FP

data['exportTime'] = '2026-10-09 12:00:00'
OUT.write_text(json.dumps(data, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')
print(f'OK → {OUT.name}: {len(cv["tag"])} tagov, {len(cv["trigger"])} triggerov, {len(cv["variable"])} premenných')
