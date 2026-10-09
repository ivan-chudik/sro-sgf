# Tracking — sro.sgf.sk + tickets.sgf.sk (GTM-KKRD2SZH)

Basic Consent Mode v2, lišta z **Complianz free**, súhlas do GTM posiela náš bridge tag.
Súhlas sa zdieľa medzi subdoménami cez cookie `sgf_ev_consent` na `.sgf.sk` (bez URL parametrov).

| Súbor | Čo to je |
|---|---|
| `GTM-KKRD2SZH_workspace9.json` | pôvodný export (nemeniť, je to zdroj) |
| `GTM-KKRD2SZH_sro-tickets.json` | **nový kontajner na import** |
| `build_sgf_container.py` | skript, ktorý z pôvodného exportu vyrobí nový (`python3 -I tracking/build_sgf_container.py`) |
| `gtm/*.html` | kód Custom HTML tagov (bridge, ref v odkazoch, formuláre) |

## 1 · Čo sa v kontajneri zmenilo

| | Pred | Po |
|---|---|---|
| Súhlas | `Consent Bridge` (len tickets, z `?consent=` v URL), na ostatných weboch default nastavoval Complianz Premium | `Consent - Default + Complianz Bridge` na **všetkých** weboch: default `denied`, update podľa cookies Complianzu (`cmplz_statistics`, `cmplz_marketing`), zápis do `sgf_ev_consent` na `.sgf.sk` |
| Odkazy na tickets | `Tickets Links` pridával `ref` + `consent`, predvolene `consent=all` | `Tickets Links - ref` pridáva len `ref` (zdroj pre konverzie), súhlas ide cez cookie |
| Google tagy | `NOT_SET` (pri chýbajúcom defaulte bežali bez súhlasu) | GA4 čaká na `analytics_storage`, Ads + Conversion Linker na `ad_storage` |
| Meta tagy | `NOT_NEEDED` + v šablóne natvrdo „consent: true“ = posielali sa vždy | čakajú na `ad_storage` |
| Po kliknutí „Prijať“ | — | GA Config / Ads Config / Linker / Meta Pixel sa dopália na tej istej stránke (`sgf_consent_*_granted`) |
| sro.sgf.sk | nič | `add_to_cart` (klik na odkaz do tickets, typ live/stream), `generate_lead` (Elementor formulár Pripomienka SK / Reminder EN) do GA4 aj Meta (`Lead`) |
| Google Ads SRO | — | `GAds - Conversion - Nákup SRO` (**pozastavený**, čaká na label) |
| `JS - sgf_source` | na eventových weboch vždy `direct` | na eventových weboch názov subdomény (`sro`, `sao`…), na tickets `ref` ako doteraz |

**Dopad na ostatné subdomény (sao, sto, fps, parkour, sgo):** kontajner je spoločný, zmena platí aj pre ne.
Ak tam beží Complianz (aj po expirácii), bude to fungovať rovnako. Ak na niektorej lište nie je, nebude sa tam merať nič, čo je správne.

## 2 · Nasadenie

### 2.1 sro.sgf.sk — GTM je v téme (bez pluginu)
- `elementor-widgets/partials/gtm.php`: consent default `denied` + GTM snippet v `<head>` a `<noscript>` za `<body>`.
- Nevkladá sa v administrácii ani v náhľade Elementor editora.
- Iné ID kontajnera: `define( 'XN_GTM_ID', 'GTM-XXXXXXX' );` vo `wp-config.php`.
- Na sro **neinštaluj GTM4WP**, kontajner by sa načítal 2×.

### 2.2 sro.sgf.sk — Complianz free
1. Pluginy → Pridať → **Complianz – GDPR/CCPA Cookie Consent** → spustiť wizard.
2. Región: EÚ (GDPR). Štatistiky: **Google Tag Manager**. Kód GTM vkladá GTM4WP, preto ak sa wizard pýta na ID kontajnera, nechaj Complianz kód **nevkladať**.
3. Služby / integrácie: Google Analytics, Google Ads, Meta (Facebook) Pixel. Všetky idú cez GTM.
4. Lišta: tlačidlá **Prijať / Odmietnuť / Nastavenia**, odmietnutie rovnako výrazné ako prijatie. Do textu lišty doplň vetu, že súhlas platí pre weby podujatí SGF na `*.sgf.sk` vrátane predaja vstupeniek (`tickets.sgf.sk`).
5. Nechaj Complianz vygenerovať stránku **Zásady cookies** (SK aj EN). Do pätičky daj odkaz na ňu a na zmenu súhlasu. Z nej odkáž na GDPR na `www.sgf.sk`.
6. **Overiť:** GTM sa musí načítať aj pred súhlasom (DevTools → Network → `gtm.js`). Ak ho Complianz blokuje, v Integrations vypni blokovanie GTM.

### 2.3 tickets.sgf.sk — bez lišty, bez Complianzu (ako doteraz)
- Súhlas sa preberá z eventovej stránky (sro, sao, sto…) cez `sgf_ev_consent`, na tickets sa už neklikne druhýkrát.
- Kto príde na tickets priamo, bez súhlasu z eventovej stránky, nemeria sa (`denied`), rovnako ako doteraz bez `?consent=`.
- GTM4WP → WooCommerce: **Track e-commerce** vypnuté. `purchase` posiela mu-plugin (`WooCommerceManager::pushPurchaseToDataLayer`), inak bude nákup 2×.
- GTM4WP → Page variables: autor (meno, ID) vypnutý.
- GTM4WP → Consent mode & consent tools → **Google Consent Mode: zapnúť**. Z príznakov zapni len **Functionality Storage** a **Security Storage**, ostatné nechaj vypnuté (= `denied`).
  Default sa tak nastaví ešte pred načítaním kontajnera, rovnako ako na sro z témy. Záložky Cookiebot / CookieYes… nechaj vypnuté.

### 2.4 Import do GTM
1. GTM → Admin → **Import Container** → súbor `GTM-KKRD2SZH_sro-tickets.json`.
2. Workspace: **New** (napr. „sro + tickets consent“). Import option: **Overwrite**.
   Pri Merge by v kontajneri ostali staré tagy `Consent Bridge` a `Tickets Links`.
3. Ak sa od 7. 10. 2026 v kontajneri niečo menilo, uvidíš to v prehľade zmien workspace ako odstránené položky. Pred publikovaním ich prenes späť.
4. Preview → test podľa časti 3 → **Submit / Publish**.

### 2.5 GA4 a Google Ads
- GA4 → Admin → Data streams → stream → Configure tag settings → **List unwanted referrals**: `tickets.sgf.sk`, `sro.sgf.sk` (a ďalšie eventové subdomény). Ak platba presmeruje na bránu (Stripe Checkout, GoPay…), pridaj aj jej doménu.
- GA4 → Admin → Events → označ `purchase` a `generate_lead` ako **Key event**.
- Google Ads: keď budeš mať konverziu pre Christmas Nitra, v GTM nastav premennú `ADS-LABEL-SRO` (a `ADS-CONV-ID-SRO`, ak je to iný účet než `850307814`). Potom tag `GAds - Conversion - Nákup SRO` **odpauzuj**.
  Konverzia sa počíta, keď prišiel nákupca na tickets cez odkaz zo sro (`ref=sro`).

## 3 · Test (GTM Preview + DevTools, inkognito okno)

| # | Kroky | Očakávané |
|---|---|---|
| 1 | Otvor sro.sgf.sk, nič neklikaj | Preview → Consent: všetko `denied`. Tagy GA/Ads/Meta **Not fired**. V Network nič na `google-analytics.com`, `facebook.com/tr` |
| 2 | Klikni **Odmietnuť** | Stále nič nebeží. Cookie `sgf_ev_consent=a0m0` (doména `.sgf.sk`) |
| 3 | Zmeň súhlas na **Prijať všetko** | Event `sgf_consent_update` + `sgf_consent_*_granted`. Vystrelí GA Config, Ads Config, Linker, Meta Pixel. Cookie `a1m1` |
| 4 | Obnov stránku | Consent hneď `granted`, tagy idú na Container Loaded / Page View, žiadny `*_granted` event |
| 5 | Klik na „Kúpiť vstupenku“ (live aj stream tab) | `GA4 - Add To Cart` + `Meta - Add To Cart`, `item_category` = `in-person` / `livestream`. Odkaz má `&ref=sro` |
| 6 | Na tickets.sgf.sk | Consent `granted` hneď pri načítaní (zo `sgf_ev_consent`), `JS - sgf_source` = `sro` |
| 7 | Testovacia objednávka → thank-you | Konzola: `dataLayer` obsahuje **jeden** `purchase`. Vystrelí GA4 - Purchase, Meta - Purchase, (Nákup SRO po odpauznutí) |
| 8 | Nové inkognito → sro → Odmietnuť → tickets | Na tickets všetko `denied`, nič nebeží |
| 9 | sro → formulár Pripomienka | Event `generate_lead`, `form_name` = `Pripomienka SK`. Vystrelí GA4 - Generate Lead, Meta - Lead (len po súhlase) |
| 10 | GA4 → Realtime / DebugView, Meta Events Manager → Test events | eventy prichádzajú, `event_source` = `sro` |

## 4 · Limity a čo sledovať
- Bridge číta cookies Complianzu (`cmplz_statistics`, `cmplz_marketing`, `cmplz_banner-status`). Po veľkom update Complianzu zopakuj test z časti 3.
- Default consent sa nastavuje Custom HTML tagom na Consent Initialization. Vždy over v Preview, že `denied` je nastavené ešte **pred** prvým Google tagom.
- Log súhlasov Complianz free nemá (je v Premium).
- `ref` na tickets drží `sessionStorage` (zdroj nákupu pre Ads konverziu). Po zatvorení karty sa stratí.
