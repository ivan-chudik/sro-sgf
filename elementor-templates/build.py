#!/usr/bin/env python3
"""Generuje importovateľné Elementor šablóny (Templates → Saved Templates → Import).

  python3 elementor-templates/build.py

Výstup: christmas-nitra-sk.json, christmas-nitra-en.json, nav-sk.json, nav-en.json,
        reminder-form-sk.json, reminder-form-en.json, maintenance.json, en-overrides.json
SK stránka používa defaulty widgetov (prázdne settings), EN stránka má vyplnené EN texty.
"""
import hashlib
import json
import os

OUT = os.path.dirname(os.path.abspath(__file__))
THEME = 'https://sro.sgf.sk/wp-content/themes/hello-elementor-child/elementor-widgets/assets/'

ORDER = ['header', 'hero', 'facts', 'ticker', 'two-paths', 'reasons', 'pricing', 'schedule',
         'competition', 'livestream', 'faq', 'venue', 'reminder', 'partners', 'footer', 'sticky-cta']


def uid(*parts):
    return hashlib.md5('|'.join(map(str, parts)).encode()).hexdigest()[:7]


def items(key, rows):
    return [dict(row, _id=uid(key, i)) for i, row in enumerate(rows)]


def p(text):
    return '<p>' + text + '</p>'


EN = {
    'header': {
        'cta_text': 'Buy tickets',
    },
    'hero': {
        'btn1_url': {'url': '#tickets', 'is_external': '', 'nofollow': ''},
        'btn2_url': {'url': '#tickets', 'is_external': '', 'nofollow': ''},
        'date_text': '26 – 29 November 2026 · Nitra, Slovakia',
        'claim': 'Four days. One arena. <b>World-class rhythmic gymnastics</b> — live in Nitra or on the livestream, wherever you are.',
        'image_alt': 'Rhythmic gymnast with ribbon',
        'btn1_text': 'I want to be there',
        'btn2_text': 'Watch online',
        'countdown_label': 'days to go',
        'countdown_note': 'Early Bird prices until <em>31 Oct 2026</em>',
    },
    'facts': {
        'items': items('facts', [
            {'label': 'Edition', 'value': '27', 'suffix': 'th', 'text': p('WG event and the 27th international invitational competition — one of the longest-running rhythmic gymnastics traditions in Slovakia.')},
            {'label': 'Competition days', 'value': '4', 'suffix': '26 – 29 Nov', 'text': p('Thursday to Sunday. Qualifications, finals and the Slovak National Championships under one roof.')},
            {'label': 'Field', 'value': 'INT', 'suffix': '', 'text': p('International invitational competition — clubs from Slovakia and abroad, individuals and groups.')},
            {'label': 'Apparatus', 'value': '5', 'suffix': '', 'text': p('Rope, hoop, ball, clubs and ribbon — five apparatus that decide every point.')},
        ]),
    },
    'ticker': {
        'items': items('ticker', [
            {'text': 'Rope', 'grad': 'yes'}, {'text': 'Hoop', 'grad': 'yes'}, {'text': 'Ball', 'grad': 'yes'},
            {'text': 'Clubs', 'grad': 'yes'}, {'text': 'Ribbon', 'grad': 'yes'},
            {'text': 'Christmas Nitra 2026', 'grad': ''}, {'text': '26 – 29 Nov · Nitra', 'grad': ''},
        ]),
    },
    'two-paths': {
        'anchor_id': 'ways',
        'eyebrow': 'Choose your experience',
        'title': 'Two ways to Christmas Nitra',
        'lead': p('Come to the arena and feel the finals atmosphere for yourself — or switch on the livestream and follow every routine from the comfort of home.'),
        'c1_tag': 'Live in Nitra',
        'c1_title': 'Be there in person',
        'c1_text': p('Four days of rhythmic gymnastics, an international field and Nitra in the run-up to Christmas. A ticket for one day or for the whole competition.'),
        'c1_features': 'Access to all competition sessions of the day\nSaturday finals and award ceremony\n1-day, 2-day or 4-day pass',
        'c1_price_from': 'from', 'c1_price': '€6.80', 'c1_price_unit': '/ 1 day',
        'c1_eb_date': 'until 31 Oct',
        'c1_btn_text': 'Buy tickets',
        'c1_foot': 'tickets.sgf.sk · e-ticket with QR code',
        'c2_tag': 'Livestream',
        'c2_title': 'Watch from anywhere',
        'c2_text': p('The whole competition live in HD. Buy access at tickets.sgf.sk, watch at stream.sgf.sk — on your phone, laptop or TV.'),
        'c2_features': 'All four days streamed, finals included\nAccess for 1 day, 2 days or a 4-day pass\nOne access, every device — phone, laptop, TV',
        'c2_price_from': 'from', 'c2_price': '€6.80', 'c2_price_unit': '/ 1 day',
        'c2_eb_date': 'until 31 Oct',
        'c2_btn_text': 'Buy online access',
        'c2_foot': 'stream.sgf.sk · access right after payment',
    },
    'reasons': {
        'anchor_id': 'why',
        'eyebrow': 'Why Christmas Nitra',
        'title': 'Three reasons to book this weekend',
        'reasons': items('reasons', [
            {'num': '01', 'title': 'World-class field', 'text': p('An official World Gymnastics event and an international invitational competition — gymnasts from across Europe and the world, judges with international brevets. Routines you usually only see on TV, watched from the stands.')},
            {'num': '02', 'title': '27 years of tradition', 'text': p('One of the longest-running rhythmic gymnastics competitions in Slovakia — the 27th edition under the banner of ŠK ŠOG Nitra and the Slovak Gymnastics Federation.')},
            {'num': '03', 'title': 'Nitra before Christmas', 'text': p("Gymnastics, music and a festive atmosphere — the perfect family weekend before Christmas. Four days of programme, pick the one that's yours.")},
        ]),
        'moments': items('moments', [
            {'image': {'url': THEME + 'photo/moment-ribbon.jpg', 'id': ''}, 'alt': 'Ribbon routine — Christmas Nitra', 'caption': 'Ribbon routine'},
            {'image': {'url': THEME + 'photo/moment-group-2022.jpg', 'id': ''}, 'alt': 'Group photo of the gymnasts in the Nitra City Sports Hall', 'caption': 'International field'},
            {'image': {'url': THEME + 'photo/moment-podium.jpg', 'id': ''}, 'alt': 'Podium — Christmas Nitra', 'caption': 'Podium'},
        ]),
        'credit': 'Photo: Daniel Palhegyi, Igor Skačan · Christmas Nitra',
    },
    'pricing': {
        'anchor_id': 'tickets',
        'eyebrow': 'Tickets & prices',
        'title': 'One day, two days or the whole competition',
        'badge_prefix': 'until',
        'badge_date': '31 Oct 2026',
        'lead': p('Same prices for attending in person and for the livestream. Early Bird applies until 31 October 2026, then full price. Prices are final — no extra fees.'),
        'tab_live_title': 'Live in Nitra', 'tab_live_sub': 'Arena ticket · e-ticket with QR code', 'tab_live_meta': 'In person',
        'tab_stream_title': 'Livestream', 'tab_stream_sub': 'Online broadcast · phone, laptop, TV', 'tab_stream_meta': 'stream.sgf.sk',
        'mode_live_label': 'Live in the arena', 'mode_stream_label': 'Livestream · online',
        'plans': items('plans', [
            {'best': '', 'badge': '', 'k': '1 day', 'title_live': 'One day', 'title_stream': 'Livestream — 1 day',
             'sub_live': 'Entry to any one competition day', 'sub_stream': 'Broadcast of any one competition day',
             'eb_label': 'Early Bird until 31 Oct', 'price': '€6.80', 'was_label': 'From 1 Nov', 'was_price': '€8.00',
             'per': 'Price per day <b>€6.80</b>',
             'features': 'All competition sessions of the day | The whole competition day live\nIdeal for the Saturday finals\nE-ticket with QR code | Access right after payment',
             'btn_live': 'Buy ticket · 1 day', 'btn_stream': 'Buy livestream · 1 day', 'btn_style': 'grad'},
            {'best': '', 'badge': '', 'k': '2 days', 'title_live': 'Two days', 'title_stream': 'Livestream — 2 days',
             'sub_live': 'Any two competition days', 'sub_stream': 'Broadcast of two competition days',
             'eb_label': 'Early Bird until 31 Oct', 'price': '€12.75', 'was_label': 'From 1 Nov', 'was_price': '€15.00',
             'per': 'Price per day <b>€6.38</b>',
             'features': 'E.g. Saturday finals + Sunday National Championships\nAll sessions on both days | Both days live on every device\nE-ticket with QR code | Access right after payment',
             'btn_live': 'Buy ticket · 2 days', 'btn_stream': 'Buy livestream · 2 days', 'btn_style': 'grad'},
            {'best': 'yes', 'badge': 'Best value', 'k': '4 days', 'title_live': '4-day pass', 'title_stream': 'Livestream — 4-day pass',
             'sub_live': 'The whole competition — Thursday to Sunday', 'sub_stream': 'The whole competition live — Thursday to Sunday',
             'eb_label': 'Early Bird until 31 Oct', 'price': '€17.00', 'was_label': 'From 1 Nov', 'was_price': '€20.00',
             'per': 'Price per day <b>€4.25</b> — two extra days included',
             'features': 'All four days, finals included\nOne ticket, no re-buying | One access, every device\nSaturday finals and weekend National Championships included',
             'btn_live': 'Buy 4-day pass', 'btn_stream': 'Buy livestream · 4 days', 'btn_style': 'primary'},
        ]),
        'fine': p('<b>Early Bird</b> applies until 31 October 2026; from 1 November the full price applies. Prices are final for the buyer — no additional fees. Sales and payment via <a href="https://tickets.sgf.sk/christmas-nitra-tickets/" target="_blank" rel="noopener">tickets.sgf.sk</a>; watch the livestream at <a href="https://stream.sgf.sk/" target="_blank" rel="noopener">stream.sgf.sk</a>. The detailed timetable will be published after the entry deadline on 18 October 2026.'),
    },
    'schedule': {
        'anchor_id': 'schedule',
        'eyebrow': 'Competition schedule',
        'title': 'Four days, four different experiences',
        'lead': p('Provisional schedule according to the directives — three competitions under one roof: the <b>WG</b> World Gymnastics event, the <b>Open</b> international invitational competition and the <b>SVK</b> National Group Championships. Exact times will be published after the entry deadline on 18 October 2026.'),
        'days': items('days', [
            {'label': 'Day 1', 'title': 'Thursday', 'date': '26 November 2026', 'btn_text': 'Ticket · day 1', 'btn_style': 'grad',
             'items': 'WG | Training and podium training | seniors and juniors\nOpen | Individuals · day 1 | international invitational competition'},
            {'label': 'Day 2', 'title': 'Friday', 'date': '27 November 2026', 'btn_text': 'Ticket · day 2', 'btn_style': 'grad',
             'items': 'WG | Qualification · all-around | seniors and juniors · hoop, ball, clubs, ribbon\nOpen | Individuals · day 2 | international invitational competition'},
            {'label': 'Day 3', 'title': 'Saturday', 'date': '28 November 2026', 'btn_text': 'Ticket · finals', 'btn_style': 'primary',
             'items': 'WG | Apparatus finals | hoop, ball, clubs, ribbon · award ceremony\nOpen | Individuals and groups\nSVK | Slovak National Group Championships'},
            {'label': 'Day 4', 'title': 'Sunday', 'date': '29 November 2026', 'btn_text': 'Ticket · day 4', 'btn_style': 'grad',
             'items': 'Open | Groups | doubles and triplets\nSVK | Slovak National Group Championships | award ceremony'},
        ]),
        'note_label': 'Provisional schedule',
        'note_text': p('Session times and start lists will be added after 18 October 2026. The organiser reserves the right to adjust the schedule according to the number of entries.'),
    },
    'competition': {
        'anchor_id': 'competition',
        'eyebrow': 'For clubs and gymnasts',
        'title': 'Who competes, and in what',
        'lead': p('Age categories, competition programmes and documents according to the directives. Times and start lists will be added after the entry deadline.'),
        'box1_k': 'Age categories',
        'box1_title': 'From the youngest to seniors',
        'box1_text': p('Three competitions under one roof — the World Gymnastics event, the Open international invitational competition and the Slovak National Group Championships.'),
        'box1_list': 'WG | Seniors and juniors | individuals with a FIG licence · all-around and apparatus finals\n'
                     'Open | Individuals | born 2019 to 2013 · juniors 2011–2012 · seniors 2010 and older\n'
                     'Open | Groups, doubles and triplets | Babies 2018–2019 · Children 2017 and younger · Hopes 2015 and younger · PreJunior 2013 and younger · Junior 2011–2012 · Senior 2010 and older\n'
                     'SVK | Groups | Slovak National Championships',
        'box2_k': 'Competition programmes',
        'box2_title': 'Individuals and groups',
        'box2_text': p('Rhythmic gymnastics with apparatus — rope, hoop, ball, clubs and ribbon.'),
        'box2_chips_grad': 'Individuals\nGroups',
        'box2_chips': 'Rope\nHoop\nBall\nClubs\nRibbon',
        'box2_note': p('<b>WG:</b> the all-around with hoop, ball, clubs and ribbon doubles as qualification; on Saturday the top 8 per apparatus go on to the apparatus finals. <b>Open:</b> individuals perform up to 2 routines by year of birth, groups by category.'),
        'box3_k': 'Documents',
        'box3_title': 'Downloads',
        'box3_text': p('Directives, registration, timetable, start lists and results — all in one place.'),
        'box3_docs': items('docs', [
            {'title': 'WG event directives 2026', 'url': {'url': THEME + 'docs/christmas-nitra-2026-directives-wg.pdf', 'is_external': '', 'nofollow': ''}, 'meta': 'PDF · EN'},
            {'title': 'Open (non-FIG) directives 2026', 'url': {'url': THEME + 'docs/christmas-nitra-2026-directives-open.pdf', 'is_external': '', 'nofollow': ''}, 'meta': 'PDF · EN'},
            {'title': 'Open directives 2026 · Slovak version', 'url': {'url': THEME + 'docs/christmas-nitra-2026-rozpis-open-sk.pdf', 'is_external': '', 'nofollow': ''}, 'meta': 'PDF · SK'},
            {'title': 'Registration · KSIS (rgform.eu)', 'url': {'url': 'https://www.rgform.eu/menu.php?akcia=NP&id_prop=4181', 'is_external': 'on', 'nofollow': ''}, 'meta': 'Online'},
            {'title': 'Training and competition timetable', 'url': {'url': '', 'is_external': '', 'nofollow': ''}, 'meta': 'After 18 Oct'},
            {'title': 'Start lists', 'url': {'url': '', 'is_external': '', 'nofollow': ''}, 'meta': 'After 18 Oct'},
            {'title': 'Results', 'url': {'url': '', 'is_external': '', 'nofollow': ''}, 'meta': 'After the event'},
        ]),
        'box3_note': p('<b>Deadlines for clubs:</b> definitive registration 30 Sept 2026 · nominative registration 18 Oct 2026 · music upload to KSIS 1 Nov 2026 · <a href="mailto:christmas.nitra@gmail.com">christmas.nitra@gmail.com</a>'),
    },
    'livestream': {
        'title': "Can't make it? Miss nothing.",
        'lead': p('All four days are streamed live. Buy access the same way you buy a ticket and watch on any device.'),
        'steps': items('steps', [
            {'title': 'Buy online access', 'text': 'at tickets.sgf.sk — for a single day or for the whole competition.'},
            {'title': 'Sign in at stream.sgf.sk', 'text': 'with the same e-mail you used for the purchase.'},
            {'title': 'Watch live', 'text': 'all competition sessions of the day — on your phone, laptop or smart TV.'},
        ]),
        'btn1_text': 'Buy online access',
        'mock_label': 'Livestream preview on a laptop and a phone',
        'lap_title': 'Christmas Nitra 2026 · Finals',
        'lap_sub': 'Ribbon · Sat 28 Nov · Nitra',
        'ph_title': 'Groups',
        'ph_sub': 'Sun 29 Nov · Nationals',
    },
    'faq': {
        'eyebrow': 'Frequently asked questions',
        'title': 'Before you buy a ticket',
        'items': items('faq', [
            {'open': 'yes', 'question': 'Where is the competition held and how do I get there?', 'answer': p("At the Nitra City Sports Hall — Dolnočermánska 105, Nitra – Klokočina — from Thursday 26 to Sunday 29 November 2026. It's under an hour by car from Bratislava and about 90 minutes from Vienna; parking is available right at the hall.")},
            {'open': '', 'question': 'When are the finals?', 'answer': p('The WG apparatus finals (hoop, ball, clubs, ribbon) take place on Saturday 28 November, followed by the award ceremony. The weekend also hosts the Slovak National Group Championships, and Sunday belongs to the Open groups, doubles and triplets.')},
            {'open': '', 'question': 'Is a ticket valid for the whole day?', 'answer': p('Yes — a day ticket covers all competition sessions of that day, and you can leave and come back. The 2-day ticket and the 4-day pass work the same way for each of the chosen days.')},
            {'open': '', 'question': 'What does Early Bird mean?', 'answer': p('A discounted price for purchases until 31 October 2026 — it applies equally to arena tickets and to the livestream. From 1 November the full price applies. Prices are final, nothing is added.')},
            {'open': '', 'question': 'How does the livestream work?', 'answer': p('Buy access at tickets.sgf.sk and watch at stream.sgf.sk after signing in with the same e-mail. It works on phones, laptops and smart TVs. Access is active right after payment.')},
            {'open': '', 'question': 'Can I return a ticket or move it to another day?', 'answer': p("Tickets are tied to a specific day or days. Refund and exchange conditions follow the terms and conditions of tickets.sgf.sk — you'll find them at checkout.")},
            {'open': '', 'question': 'Who organises the competition?', 'answer': p('ŠK ŠOG Nitra – rhythmic gymnastics (MODERGYM) in cooperation with the Slovak Gymnastics Federation and the Secondary Sports School Nitra. The event is an official World Gymnastics competition. Contact: Zuzana Vilčeková, <a href="mailto:christmas.nitra@gmail.com">christmas.nitra@gmail.com</a>, +421 911 430 001.')},
        ]),
    },
    'venue': {
        'anchor_id': 'venue',
        'eyebrow': 'Venue',
        'title': 'Nitra, 26 – 29 November',
        'addr_name': 'Nitra City Sports Hall',
        'addr_street': 'Dolnočermánska 105, 949 01 Nitra – Klokočina, Slovakia',
        'info': items('info', [
            {'label': 'Getting there', 'text': p("Under 60 minutes by car from Bratislava, about 90 minutes from Vienna. The hall is in the Klokočina district, a few minutes from Nitra's centre.")},
            {'label': 'Parking', 'text': p('Parking right at the hall on Dolnočermánska street.')},
            {'label': 'Entry', 'text': p('Doors open one hour before the first session. Just show your e-ticket with QR code on your phone.')},
            {'label': 'For visitors', 'text': p('Your ticket is valid all day — leave between sessions and come back. Festive Nitra is a few minutes from the hall.')},
            {'label': 'The hall', 'text': p('One competition floor and two training floors, 15 m ceiling height — the home of Christmas Nitra.')},
            {'label': 'Accommodation', 'text': p('Partner hotels with the code “Christmas Nitra 2026”: H11, Centrum, City, OKO and Zobor.')},
        ]),
        'btn1_text': 'Buy tickets',
        'btn2_text': 'Open in maps',
        'photo_alt': 'Nitra City Sports Hall – Klokočina',
    },
    'reminder': {
        'eyebrow': 'Still deciding?',
        'title': "We'll remind you before sales open and before the competition",
        'lead': p('No spam — three e-mails at most: sales opening, the schedule and the day before the event.'),
        'note': p('By submitting you agree to the processing of your e-mail for this reminder. <a href="https://www.sgf.sk/sk/article/gdpr" target="_blank" rel="noopener">Privacy policy</a>'),
    },
    'partners': {
        'label': 'Organisers and partners',
        'logos': items('logos', [
            {'image': {'url': THEME + 'logos/' + f, 'id': ''}, 'alt': a, 'height': {'size': h, 'unit': 'px'}}
            for f, a, h in [
                ('sog-nitra-white.png', 'ŠK ŠOG Nitra — rhythmic gymnastics', 34),
                ('sgf-white.png', 'Slovak Gymnastics Federation', 26),
                ('sss-white-hd.png', 'Secondary Sports School Nitra', 56),
                ('wg-white-hd.png', 'World Gymnastics', 40),
                ('eg-white.png', 'European Gymnastics', 40),
                ('min-cestovny-ruch-sport-white.png', 'Ministry of Tourism and Sports of the Slovak Republic', 46),
                ('min-skolstva-white.png', 'Ministry of Education, Research, Development and Youth of the Slovak Republic', 46),
            ]
        ]),
    },
    'footer': {
        'columns': items('columns', [
            {'title': 'Organiser', 'social': '', 'content': p('ŠK ŠOG Nitra – rhythmic gymnastics | MODERGYM<br />Slančíkovej 2, 950 50 Nitra, Slovakia<br />Zuzana Vilčeková · <a href="tel:+421911430001">+421 911 430 001</a><br /><a href="mailto:christmas.nitra@gmail.com">christmas.nitra@gmail.com</a> · <a href="http://www.gymnastikanitra.sk/" target="_blank" rel="noopener">gymnastikanitra.sk</a>')},
            {'title': 'Slovak Gymnastics Federation', 'social': 'yes', 'content': p('Olympijské námestie 1, 832 80 Bratislava, Slovakia<br /><a href="mailto:office@sgf.sk">office@sgf.sk</a> · <a href="https://sgf.sk" target="_blank" rel="noopener">www.sgf.sk</a><br />in cooperation with the Secondary Sports School Nitra')},
            {'title': 'Tickets & livestream', 'social': '', 'content': p('Support: <a href="mailto:roman@alttag.media">roman@alttag.media</a><br /><a href="https://tickets.sgf.sk/christmas-nitra-tickets/" target="_blank" rel="noopener">tickets.sgf.sk</a> · <a href="https://stream.sgf.sk/" target="_blank" rel="noopener">stream.sgf.sk</a>')},
            {'title': 'Follow us', 'social': '', 'content': p('<a href="https://www.facebook.com/gymnastikanitra/" target="_blank" rel="noopener">Facebook · ŠK ŠOG Nitra</a><br /><a href="https://www.instagram.com/christmasnitra2026/" target="_blank" rel="noopener">Instagram · @christmasnitra2026</a><br /><a href="https://www.instagram.com/sksognitra_rhythmic_gymnastics/" target="_blank" rel="noopener">Instagram · @sksognitra_rhythmic_gymnastics</a>')},
        ]),
        'social_label': 'SGF on social media',
        'fb_label': 'SGF on Facebook',
        'ig_label': 'SGF on Instagram',
        'yt_label': 'SGF on YouTube',
        'bottom_left': '© 2026 Slovak Gymnastics Federation · ŠK ŠOG Nitra',
        'bottom_right': 'Christmas Nitra — Slovak Rhythmic Gymnastics Open · WG event and 27th international invitational competition',
    },
    'sticky-cta': {
        'btn1_text': 'Tickets',
    },
}


def container(key, children):
    return {
        'id': uid('con', key),
        'elType': 'container',
        'isInner': False,
        'settings': {
            'content_width': 'full',
            'padding': {'unit': 'px', 'top': '0', 'right': '0', 'bottom': '0', 'left': '0', 'isLinked': True},
            'flex_gap': {'unit': 'px', 'size': 0, 'column': '0', 'row': '0', 'isLinked': True},
        },
        'elements': children,
    }


def widget(key, wtype, settings):
    return {'id': uid('w', key), 'elType': 'widget', 'widgetType': wtype, 'isInner': False, 'settings': settings, 'elements': []}


def template(title, ttype, content, page_settings=None):
    return {'version': '0.4', 'title': title, 'type': ttype, 'content': content, 'page_settings': page_settings or []}


def write(name, data):
    with open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=1)


PAGE_SETTINGS = {'template': 'elementor_canvas', 'hide_title': 'yes'}

for lang in ('sk', 'en'):
    content = [container(lang + w, [widget(lang + w, w, EN.get(w, {}) if lang == 'en' else {})]) for w in ORDER]
    write(f'christmas-nitra-{lang}.json', template(f'Christmas Nitra 2026 — landing {lang.upper()}', 'page', content, PAGE_SETTINGS))

    nav = widget('nav' + lang, 'nav-menu', {
        'menu': f'christmas-nitra-{lang}',
        'layout': 'horizontal',
        'pointer': 'none',
        'dropdown': 'tablet',
        'toggle': 'burger',
        'full_width': '',
        'align_items': 'end',
    })
    write(f'nav-{lang}.json', template(f'CN Nav Menu {lang.upper()}', 'container', [container('nav' + lang, [nav])]))

    t = {
        'sk': ('Pripomienka SK', 'tvoj@email.sk', 'Pripomeň mi', 'Ďakujeme! Pripomienku ti pošleme e-mailom.', 'Toto pole je povinné.', 'Zadaj platný e-mail.'),
        'en': ('Reminder EN', 'your@email.com', 'Remind me', "Thank you! We'll send you a reminder by e-mail.", 'This field is required.', 'Please enter a valid e-mail.'),
    }[lang]
    form = widget('form' + lang, 'form', {
        'form_name': t[0],
        'form_fields': [{'_id': uid('field', lang), 'custom_id': 'email', 'field_type': 'email', 'field_label': 'E-mail',
                         'placeholder': t[1], 'required': 'true', 'width': '100'}],
        'show_labels': '',
        'button_text': t[2],
        'submit_actions': ['save-to-database'],
        'success_message': t[3],
        'required_field_message': t[4],
        'invalid_message': t[5],
        'custom_messages': 'yes',
    })
    write(f'reminder-form-{lang}.json', template(f'CN Formulár pripomienky {lang.upper()}', 'container', [container('form' + lang, [form])]))

write('maintenance.json', template('Christmas Nitra — Maintenance', 'page',
                                   [container('maint', [widget('maint', 'maintenance', {})])], PAGE_SETTINGS))
write('en-overrides.json', EN)
print('ok')
