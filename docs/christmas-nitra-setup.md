# Christmas Nitra 2026 — nasadenie na sro.sgf.sk

17 custom Elementor widgetov (16 blokov landing page + `maintenance`) v
`wp-content/themes/hello-elementor-child/elementor-widgets/`. Kategória v paneli:
**Christmas Nitra**, názvy widgetov začínajú `CN ·`.

## 1 · Téma
1. Nahraj `wp-content/themes/hello-elementor-child/` na hosting (parent **Hello Elementor** musí byť nainštalovaný) a aktivuj child tému.
   Ak už child téma existuje, stačí priečinok `elementor-widgets/` + riadok v `functions.php`:
   `require_once get_stylesheet_directory() . '/elementor-widgets/register.php';`
2. Obrázky, logá a PDF sú v `elementor-widgets/assets/` a sú nastavené ako defaulty widgetov, takže stránka funguje hneď.
   Neskôr ich môžeš v paneli vymeniť za súbory z Media Library.

## 2 · Fonty (dôležité)
1. **Elementor → Custom Fonts → Add New** → názov presne `Glacial Indifference`, nahraj
   `fonts/GlacialIndifference-Regular.woff` (400) a `GlacialIndifference-Bold.woff` (700) z `design-source`.
2. **Site Settings → Global Fonts**: *Primary* = Glacial Indifference, *Text* = Jost (váhy 400/500/700).
   Elementor načítava Custom/Google fonty len vtedy, keď sú niekde použité, a Global Fonts zaručia, že sa načítajú na každej stránke.
   Widgety fonty volajú cez CSS tokeny `--xn-font` / `--xn-font-text` (v `register.php`).
3. V Site Settings nenastavuj globálne farby odkazov a nadpisov, lebo by sa bili s dizajnom.

## 3 · WordPress menu (pre natívny Nav Menu)
Appearance → Menus → 2 menu s **Custom Links**:

| Menu (názov → slug) | Položky (Vlastné odkazy: URL → text) |
|---|---|
| `Christmas Nitra SK` → `christmas-nitra-sk` | `/#vstupenky` Vstupenky · `/#program` Program · `/#sutaz` Súťaž · `/#livestream` Livestream · `/#miesto` Miesto · `/#faq` FAQ |
| `Christmas Nitra EN` → `christmas-nitra-en` | `/en/#tickets` Tickets · `/en/#schedule` Schedule · `/en/#competition` Competition · `/en/#livestream` Livestream · `/en/#venue` Venue · `/en/#faq` FAQ |

Kotvy sekcií (Anchor ID vo widgetoch): SK = defaulty widgetov (`top, cesty, preco, vstupenky, program, sutaz, livestream, faq, miesto`),
EN šablóna ich má preložené (`top, ways, why, tickets, schedule, competition, livestream, faq, venue`). Hero tlačidlá na EN smerujú na `#tickets`.

## 4 · Import šablón (`elementor-templates/`)
Templates → Saved Templates → **Import Templates**, postupne:

| Súbor | Čo to je |
|---|---|
| `nav-sk.json`, `nav-en.json` | Container s natívnym **Nav Menu** (hamburger pod 1100 px). Ak po importe nie je vybrané menu, vyber ho v šablóne. |
| `reminder-form-sk.json`, `reminder-form-en.json` | Container s natívnym **Elementor Form** (1 pole e-mail + tlačidlo, akcia *Collect Submissions*). E-mail notifikáciu/Mailchimp doplň v *Actions After Submit*. |
| `christmas-nitra-sk.json` | Celá SK stránka (16 widgetov, texty = defaulty widgetov). |
| `christmas-nitra-en.json` | Celá EN stránka s vyplnenými EN textami. |
| `maintenance.json` | Coming-soon stránka. |

Šablóny `christmas-nitra-*.json` vlož na stránku (SK: úvodná `sro.sgf.sk`, EN: `sro.sgf.sk/en`) cez
priečinok v editore → My Templates → Insert, a potvrď *Apply settings*. Nastaví sa Elementor Canvas, bez titulku.
Potom v každej stránke:
- **CN · Header** → *Menu* → vyber šablónu `CN Nav Menu SK/EN`
- **CN · Pripomienka** → *Formulár* → vyber `CN Formulár pripomienky SK/EN`

Ak by sa kontajnery neimportovali presne, platí pravidlo: každý widget patrí do Containera s nastavením
*Content Width: Full*, padding 0 a gap 0.

## 5 · Jazyky a linky, ktoré netreba vypĺňať
- Všetky predajné CTA majú **prázdne URL = automaticky podľa jazyka stránky**
  (stránka pod `/en/` → `tickets.sgf.sk/christmas-nitra-tickets/`, inak `tickets.sgf.sk/sk/christmas-nitra-vstupenky/`).
  Vlastná URL v paneli má vždy prednosť.
- Prepínač SK/EN v headeri: aktívny jazyk sa určí automaticky podľa URL; odkazy `https://sro.sgf.sk/` a `https://sro.sgf.sk/en/`.
- Všetky externé linky (iná doména), aj tie vo WYSIWYG textoch, dostanú `target="_blank" rel="noopener"` automaticky. PDF sa otvárajú v novom okne.

## 6 · Maintenance mode
Elementor → Tools → Maintenance Mode → *Coming Soon* → **Choose Template** = `Christmas Nitra — Maintenance`
(musí to byť šablóna z Templates, obyčajná stránka sa v zozname neukáže).

## 7 · Čo sa bude meniť a kde
| Čo | Kde |
|---|---|
| Ceny / Early Bird | CN · Dve cesty, CN · Vstupenky a ceny (karty + poznámka), CN · FAQ, CN · Hero (poznámka pri odpočte) |
| Program po 18. 10. | CN · Program súťaže → Dni → *Bloky programu* (`štítok \| text \| doplnok`) |
| Štartové listiny, výsledky | CN · Kto súťaží → Box 3 → doplň URL (bez URL sa dokument zobrazí ako čakajúci) |
| Odpočet | CN · Hero → Odpočet → cieľový dátum `2026-11-26T09:00:00+01:00` |

SK aj EN stránku treba meniť vždy obe.

## Technické poznámky
- Zdieľaný základ: `partials/base.css` (reset scoped na `.xn-block`, `.xn-wrap`, typografia, tlačidlá `.xn-btn--primary/--grad`),
  `partials/controls.php` (trait so spoločnými controls), `partials/helpers.php` (linky, WYSIWYG, jazyk).
- Header je `position: fixed` s JS meraním výšky (`--header-bar-h`) a korekciou anchor scrollu podľa `docs/header-sticky-nav.md`.
- Hero CTA prepínajú tab v cenníku cez `data-pricing-mode="live|stream"` (listener v `pricing/script.js`).
- Style tab: controls sú bez defaultov, takže bez zásahu platí dizajn vrátane breakpointov 1100/820 px. Hodnota v paneli ho prepíše.
- `elementor-templates/build.py` generuje JSON šablóny a EN texty. Po zmene EN obsahu v zdroji spusti `python3 elementor-templates/build.py`.
