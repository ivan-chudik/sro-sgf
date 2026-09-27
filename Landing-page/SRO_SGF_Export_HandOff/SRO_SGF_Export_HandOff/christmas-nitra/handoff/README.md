# Christmas Nitra 2026 — landing page · handoff pre Elementor

Kampaňová landing page **Christmas Nitra — Slovak Rhythmic Gymnastics Open** (26.–29. 11. 2026, Nitra) v dvoch jazykových verziách (SK, EN). Balík je pripravený tak, aby sa stránka skladala **po blokoch** — každý blok má vlastný HTML fragment a vlastný CSS súbor, takže budúca zmena sa robí v jednom bloku, nie v celej stránke.

Súbory sú **referenčný dizajn v HTML/CSS** (hi-fi, finálne farby, typografia, texty, linky). Cieľ je preniesť ich 1:1 do Elementora — buď ako HTML widgety s priloženým CSS, alebo prestavať natívnymi widgetmi podľa CSS hodnôt nižšie.

---

## 1 · Štruktúra balíka

```
handoff/
├─ index.html                 prehľad všetkých blokov (otvor v prehliadači)
├─ README.md                  tento súbor
├─ sk/
│  ├─ index.html              kompletná SK stránka (poskladaná z blokov)
│  └─ sections/01-…16-*.html  16 samostatných SK blokov, každý otvoriteľný samostatne
├─ en/
│  ├─ index.html              kompletná EN stránka
│  └─ sections/01-…16-*.html  16 samostatných EN blokov (rovnaké ID a triedy ako SK)
├─ css/
│  ├─ xn-tokens.css           farby, gradient, fonty (@font-face) — načítať PRVÝ, globálne
│  ├─ 00-base.css             reset, .wrap, typografia, tlačidlá — globálne
│  ├─ 01-header.css … 16-sticky-cta.css   CSS jedného bloku (číslo = číslo bloku)
│  └─ landing.css             všetko okrem tokens zlepené do jedného súboru (alternatíva)
├─ js/landing.js              správanie (prepínanie tabov, parallax, odpočet) — 1× pred </body>
├─ assets/                    grafika: xn-ribbons.png, xn-gymnast.png, logos/, photo/
├─ docs/                      3 PDF (rozpisy) linkované zo sekcie Dokumenty
└─ fonts/                     Glacial Indifference (Regular, Bold) .woff
```

SK a EN verzia majú **identickú štruktúru, ID aj triedy** — líšia sa len textami, `lang`, odkazom na predaj vstupeniek a prepínačom jazyka.

---

## 2 · Bloky (poradie na stránke)

| # | Blok | HTML (sk/en/sections/) | CSS (css/) | Kotva / poznámka |
|---|------|------------------------|------------|------------------|
| 01 | Header / navigácia | `01-header.html` | `01-header.css` | sticky; menu linkuje na kotvy nižšie; prepínač SK/EN |
| 02 | Hero | `02-hero.html` | `02-hero.css` | `#top`; odpočet dní (`#cd-d`, JS); CTA scrollujú na `#program` a prepnú tab |
| 03 | Čísla / fakty | `03-facts.html` | `03-facts.css` | 4 dlaždice |
| 04 | Ticker | `04-ticker.html` | `04-ticker.css` | CSS marquee (náčinie + dátum), `aria-hidden` |
| 05 | Dve cesty | `05-two-paths.html` | `05-two-paths.css` | `#cesty`; svetlé pozadie (`.light`) |
| 06 | Tri dôvody + fotky | `06-reasons.html` | `06-reasons.css` | `#preco`; 3 fotky + kredit |
| 07 | Vstupenky a ceny | `07-pricing.html` | `07-pricing.css` | `#program`; taby Naživo / Livestream (JS); 3 cenové karty |
| 08 | Program súťaže | `08-schedule.html` | `08-schedule.css` | `#harmonogram`; svetlé pozadie; 4 dni, štítky WG / Open / MSR |
| 09 | Kto súťaží | `09-competition.html` | `09-competition.css` | `#sutaz`; kategórie, programy, dokumenty (PDF + KSIS) |
| 10 | Livestream | `10-livestream.html` | `10-livestream.css` | `#livestream`; mockup notebook + mobil je čisté CSS + 2 fotky |
| 11 | FAQ | `11-faq.html` | `11-faq.css` | `#faq`; natívny `<details>` akordeón, svetlé pozadie |
| 12 | Miesto konania | `12-venue.html` | `12-venue.css` | `#miesto`; adresa, 6 info buniek, foto haly, Google Maps |
| 13 | Pripomienka | `13-reminder.html` | `13-reminder.css` | e-mail formulár — **statický**, napojiť na Elementor Form / newsletter |
| 14 | Partneri | `14-partners.html` | `14-partners.css` | 7 bielych log na čiernom páse |
| 15 | Footer | `15-footer.html` | `15-footer.css` | kontakty, ikonky sociálnych sietí SGF (inline SVG) |
| 16 | Mobilná lišta CTA | `16-sticky-cta.html` | `16-sticky-cta.css` | `position:fixed`, zobrazuje sa len ≤ 820 px |

Každý súbor v `sections/` je samostatná HTML stránka s načítaným `xn-tokens.css`, `00-base.css` a CSS daného bloku — dá sa otvoriť a skontrolovať sám. Fragment na skopírovanie je ohraničený komentármi `<!-- ═══ Blok NN … ═══ -->` … `<!-- ═══ /Blok NN ═══ -->`.

---

## 3 · Postup v Elementore (odporúčaný)

1. **Fonty** — Glacial Indifference (Regular 400, Bold 700) nahrať cez *Elementor → Custom Fonts* (súbory `fonts/*.woff`); *Jost* je v Elementore ako Google Font (váhy 400, 500, 700). Ak sa fonty nahrajú cez Elementor, zmaž z `xn-tokens.css` dva riadky `@font-face` (aby sa nenačítavali dvakrát).
2. **Globálne CSS** — do *Site Settings → Custom CSS* (alebo child theme `style.css`) vlož obsah `css/xn-tokens.css` a `css/00-base.css`. Pozor na `*{margin:0;padding:0}` a `section{padding:110px 0}` v base — ak by kolidovali s témou, obmedz ich na wrapper landing page (napr. `.xn-landing section{…}`) a pridaj triedu `xn-landing` na `<body>` stránky.
3. **Bloky** — na každý blok jeden *Container* (full width, bez paddingu, pozadie podľa bloku: `#000` alebo `#fff` pre `.light`) a v ňom **HTML widget** s fragmentom zo `sections/NN-*.html`. CSS bloku vlož do *Advanced → Custom CSS* daného kontajnera (Elementor Pro) alebo tiež globálne. Cesty k obrázkom (`../../assets/…`) prepíš na URL z WordPress Media Library.
4. **JS** — obsah `js/landing.js` vlož 1× cez *Elementor → Custom Code* (pred `</body>`) alebo ako HTML widget so `<script>` na konci stránky. Skript je „guarded" — funguje aj keď na stránke nie sú všetky bloky.
5. **Kotvy** — zachovaj `id` sekcií (`top, cesty, preco, program, harmonogram, sutaz, livestream, faq, miesto`), menu v headeri a CTA na ne linkujú. Ak header stavíš natívnym *Nav Menu* widgetom, použi tieto kotvy ako položky.
6. **Mobilné menu** — prototyp skryje `<ul>` menu pod 1100 px a **nemá hamburger**. V Elementore použi natívny Nav Menu s mobilným dropdownom, alebo ponechaj len logo + tlačidlo „Kúpiť vstupenku" a spodnú CTA lištu (blok 16), ktorá na mobile preberá hlavnú navigáciu k vstupenkám.
7. **Formulár pripomienky** (blok 13) — nahraď `<form>` widgetom *Elementor Form* (1 pole e-mail + tlačidlo „Pripomeň mi" / „Remind me"), zachovaj triedy `.remind input` / `.btn.btn-primary` pre vzhľad, a text súhlasu s linkom na GDPR.
8. **Jazykové verzie** — dve samostatné stránky (napr. `/christmas-nitra/` a `/en/christmas-nitra/`); prepínač v headeri linkuje medzi nimi (`.lang a`).

---

## 4 · Linky (všetky externé sa otvárajú v novom okne — `target="_blank" rel="noopener"`)

| Účel | SK | EN |
|------|----|----|
| Predaj vstupeniek + livestream prístup (všetky CTA „Kúpiť / Vstupenka / Buy / Ticket") | `https://tickets.sgf.sk/sk/christmas-nitra-vstupenky/` | `https://tickets.sgf.sk/christmas-nitra-tickets/` |
| Livestream prehrávač | `https://stream.sgf.sk/` | rovnaké |
| Ochrana osobných údajov (GDPR) | `https://www.sgf.sk/sk/article/gdpr` | rovnaké |
| Registrácia klubov (KSIS) | `https://www.rgform.eu/menu.php?akcia=NP&id_prop=4181` | rovnaké |
| Mapa haly | `https://www.google.com/maps/search/?api=1&query=Dolnočermánska+105,+949+01+Nitra` | rovnaké |
| Web organizátora | `http://www.gymnastikanitra.sk/` | rovnaké |
| Sociálne siete organizátora | FB `facebook.com/gymnastikanitra` · IG `@christmasnitra2026` · IG `@sksognitra_rhythmic_gymnastics` | rovnaké |
| Sociálne siete SGF (ikonky vo footeri) | FB `facebook.com/slovenskagymnastickafederacia` · IG `instagram.com/slovenskagymnastickafederacia` · YT `youtube.com/channel/UCdN1r_NUaD12itn7xFBv_ag` | rovnaké |
| E-maily / telefón | `christmas.nitra@gmail.com`, `office@sgf.sk`, `roman@alttag.media` (podpora ticketingu), `+421 911 430 001` | rovnaké |

Interné CTA v hero: „Chcem byť pri tom" → `#program` + tab *Naživo*; „Sledovať online" → `#program` + tab *Livestream* (atribút `data-tab="live|stream"`, rieši JS).

Dokumenty (blok 09): `docs/christmas-nitra-2026-rozpis-open-sk.pdf` (SK), `docs/christmas-nitra-2026-directives-wg.pdf` (EN), `docs/christmas-nitra-2026-directives-open.pdf` (EN) — nahrať do Media Library a prelinkovať.

---

## 5 · Dizajnové tokeny

**Farby**
- Pozadia: `#000000` (tmavé sekcie), `#ffffff` (svetlé sekcie `.light`), karty `#0a0a0d` / `#15151b`
- Text: `#ffffff`, sekundárny `#cfcfd8`, tlmený `#9a9aa6` / `#8a8a94`, jemný `#7a7a86` / `#6f6f7a`; na svetlom `#111111`, `#444444`, `#333333`, `#666666`
- Linky / hairline: `#17171c`, `#2a2a33`, `#3a3a46`, `#23232b`; na svetlom `#e4e4ea`, `#eee`
- Akcenty: ružová `#f65b80` (eyebrow, aktívne prvky, hover), orchid `#ba58ab` (akcent na svetlom pozadí, štítok MSR), červená `#ff5e5d`
- **Brand gradient** (nadpisy, tlačidlá s obrysom, bodky, štítky WG): `linear-gradient(90deg,#ff5e5d 0%,#f65b80 22%,#df5aa0 38%,#ba58ab 52%,#8e5bae 66%,#7260b1 82%,#5762b3 100%)` — v CSS ako `var(--xn-gradient)`; text s gradientom = trieda `.xn-grad`

**Typografia**
- Display (`--xn-font`): *Glacial Indifference* 400, uppercase, letter-spacing .04em, line-height 1 — nadpisy `.disp`, veľké čísla, ceny
- Text (`--xn-font-text`): *Jost* 400 / 500 / 700 — celý bežný text (má slovenské znaky; Glacial Indifference ich nemá, preto Jost ako fallback)
- Veľkosti: H1 `clamp(54px,7.4vw,112px)`; H2 sekcie `clamp(34px,4.4vw,60px)`; lead 19px; eyebrow 12px / 700 / letter-spacing .22em uppercase; body 15–16px; drobné 12–13px; ceny `clamp(60px,5.2vw,80px)`

**Tvary a rozostupy**
- Kontajner `.wrap` max 1240px, padding 0 32px; sekcie padding 110px (mobil 72px)
- Zaoblenie: tlačidlá a štítky 999px (pill); karty 22–28px; fotky 20–24px
- Tlačidlá: výška 54px (veľké 62–64px, header 40px, mobilná lišta 48px), padding 0 28px, 13px / 700 / uppercase / letter-spacing .1em
  - `.btn-primary`: biele na čiernom (na svetlom čierne), hover: outline ring 3px pozadie + 5px `#f65b80`
  - `.btn-grad`: 2px gradientový obrys, hover: výplň gradientom
- Tiene: mockup zariadení `0 30px 70px rgba(0,0,0,.6)`; inak bez tieňov
- Animácie: pulz neaktívneho tabu 1.5s; „live" bodka 1.4s; ticker 40s linear; hover fotky scale 1.03 / .6s; rešpektuje `prefers-reduced-motion`

**Breakpointy**
- ≤ 1100 px: menu skryté, cenové karty a info boxy pod seba, program 2 stĺpce, fakty 2×2
- ≤ 820 px (mobil): všetko v jednom stĺpci, hero obrázok nad textom, výšky fotiek 260px, zobrazí sa spodná CTA lišta, skryje sa prepínač jazyka a tlačidlo v headeri

---

## 6 · Obrázky a logá

| Súbor | Použitie | Rozmer |
|------|----------|--------|
| `assets/xn-ribbons.png` | dekoratívne stuhy (hero, karty, pozadia sekcií) — `alt=""` | veľký PNG s priehľadnosťou |
| `assets/xn-gymnast.png` | hero gymnastka (výrez s priehľadnosťou) | PNG |
| `assets/photo/moment-ribbon.jpg` | dôvody · „Zostava so stuhou" + livestream mockup (notebook) | 1800×1116 |
| `assets/photo/moment-group-2022.jpg` | dôvody · „Medzinárodné štartové pole" (Mestská hala) | 1800×1200 |
| `assets/photo/moment-podium.jpg` | dôvody · „Stupne víťazov" | 1800×1116 |
| `assets/photo/moment-hoops-2024.jpg` | livestream mockup (mobil) | 1280×886 |
| `assets/photo/venue-hall.jpg` | miesto konania — Mestská športová hala Nitra | 640×410 (jediná dostupná kvalita) |
| `assets/photo/moment-clubs.jpg`, `moment-lineup.jpg`, `moment-babies.jpg` | rezerva (nepoužité na stránke) | — |
| `assets/logos/*.png` | partneri: ŠK ŠOG Nitra, SGF, SŠŠ Nitra, World Gymnastics, European Gymnastics, Min. cestovného ruchu a športu SR, Min. školstva, výskumu, vývoja a mládeže SR — biele verzie na čierne pozadie | PNG s priehľadnosťou |

Fotografie: © Daniel Palhegyi, Igor Skačan — kredit je na stránke pod fotkami (blok 06), zachovať.

---

## 7 · Obsah, ktorý sa bude meniť (kde)

- **Ceny a Early Bird** (do 31. 10. 2026, od 1. 11. plná cena): blok 05 (od 6,80 €), blok 07 (3 karty + poznámka), FAQ blok 11, hero blok 02 (text pri odpočte).
- **Časy programu a štartové listiny** (po uzávierke 18. 10. 2026): blok 08 (dni) a blok 09 (dokumenty „Po 18. 10." → linky na PDF).
- **Výsledky** po súťaži: blok 09, položka „Výsledky".
- **Odpočet**: cieľový dátum `2026-11-26T09:00:00+01:00` v `js/landing.js`.
- Texty sú v HTML fragmentoch; SK a EN treba meniť vždy oboje (rovnaké miesto v `sk/sections/…` a `en/sections/…`).

---

## 8 · Prístupnosť a SEO — čo je hotové

`lang` na `<html>`, sémantické `<header>/<section>/<footer>`, `alt` texty pri obsahových obrázkoch (dekoratívne majú `alt=""`), `aria-label` na ikonkách sociálnych sietí, `role="tablist"` + `aria-selected` na taboch, FAQ ako natívny `<details>`. Titulky stránok sú v `<title>` v `sk/index.html` a `en/index.html`.
