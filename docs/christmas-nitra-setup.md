# Christmas Nitra 2026 — nasadenie na sro.sgf.sk

19 custom Elementor widgetov (18 blokov landing page + `maintenance`) v
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
  (stránka pod `/en/` → `tickets.sgf.sk/?add-to-cart=5964`, inak `tickets.sgf.sk/sk/?add-to-cart=5964`).
  Vlastná URL v paneli má vždy prednosť.
- Prepínač SK/EN v headeri je napojený na **Polylang**: aktívny jazyk podľa jazyka stránky, odkaz vedie na jazykové dvojča aktuálnej stránky (bez prekladu na domovskú stránku jazyka). Logo vedie na domov aktuálneho jazyka. Bez Polylangu sa použijú URL z panela (`https://sro.sgf.sk/`, `https://sro.sgf.sk/en/`).
- Jazyk pre predajné linky sa berie z Polylangu (jazyk stránky), záložne z URL `/en/`.
- Všetky externé linky (iná doména), aj tie vo WYSIWYG textoch, dostanú `target="_blank" rel="noopener"` automaticky. PDF sa otvárajú v novom okne.

## 6 · Maintenance mode
Elementor → Tools → Maintenance Mode → *Coming Soon* → **Choose Template** = `Christmas Nitra — Maintenance`
(musí to byť šablóna z Templates, obyčajná stránka sa v zozname neukáže).

## 7 · Čo sa bude meniť a kde
| Čo | Kde |
|---|---|
| Ceny / Early Bird | CN · Dve cesty, CN · Vstupenky a ceny (karty + poznámka), CN · FAQ, CN · Hero (poznámka pri odpočte) |
| Program po 18. 10. | CN · Program súťaže → Dni → *Bloky programu* (`štítok \| text \| doplnok`) |
| Logá organizátorov a partnerov | CN · Partneri → *Organizátori* / *Partneri* (2 skupiny, každá vlastné zobrazenie). Biele PNG v `assets/logos/cn-*`, zdroje od klienta v `Landing-page/partneri-loga/` |
| Štartové listiny, výsledky | CN · Kto súťaží → Box 3 → doplň URL (bez URL sa dokument zobrazí ako čakajúci) |
| Odpočet | CN · Hero → Odpočet → cieľový dátum `2026-11-26T09:00:00+01:00` |

SK aj EN stránku treba meniť vždy obe.

## 8 · Video spot a Novinky (pridané 10/2026)
- **CN · Video spot** (`video`) — YouTube spot, na homepage za tickerom. Video sa načíta až po kliknutí
  (youtube-nocookie, bez cookies pred prehraním). Odkaz/ID videa: sekcia *Video*. EN stránka má zatiaľ SK spot —
  keď príde EN verzia, prepíš *YouTube odkaz* na EN stránke.
- **CN · Novinky** (`news`) — slider WordPress článkov, na homepage za *Kto súťaží*. Jazyk sa filtruje automaticky
  (Polylang), kategórie voliteľne v sekcii *Články*. Bez článkov sa sekcia na webe skryje.
- Pri článku je pole **Pôvodný článok (sgf.sk)** (bočný panel editora). Vyplnené → karta vedie na sgf.sk (nové okno),
  prázdne → na článok na sro.sgf.sk.
- Testovacie články: `wp-import/novinky-test.xml` → *Nástroje → Import → WordPress*, zaškrtni *Stiahnuť a importovať
  prílohy*. 4 SK + 4 EN články (slug `test-…`), kategórie *Novinky* / *News*. Po kontrole ich zmaž (aj obrázky `cn-test-…` v Médiách).
- Na existujúcu stránku sa widgety pridávajú ručne: nový Container (Full width, padding 0, gap 0) → widget z kategórie *Christmas Nitra*.

## 9 · Koniec Early Bird (automaticky)
- Dátum a čas je jeden pre celý web (SK aj EN): **Nastavenia → Všeobecné → Christmas Nitra — koniec Early Bird**. Prázdne = `XN_EB_END` v `partials/helpers.php` (1. 11. 2026 00:00). Plná cena 1 dňa `XN_DAY_PRICE` (8,00 €).
- Test: nastav čas o pár minút dopredu, otvor stránku a po čase obnov; po teste pole vyprázdni.
- Prepnutie robí prehliadač podľa času (skript v `register.php` → trieda `xn-eb-over` na `<html>`), takže funguje aj s cache.
  Prvky `.xn-eb-only` sa po konci skryjú, `.xn-eb-after` sa zobrazia.
- **Vstupenky a ceny:** odznak a „Early Bird do…“ zmiznú, cena = *Plná cena*, „Cena za deň“ = *Cena za deň — po skončení* (prázdne = vypočíta sa),
  úvodný text a poznámka = polia *…po skončení Early Bird* (prázdne = štandardný text SK/EN).
- **Vstupenky a ceny — ponechanie info:** *Po skončení ukázať odznak „Early Bird skončil“* a *…prečiarknutú Early Bird cenu* (obe predvolene zapnuté).
- **Dve cesty:** cena = *Cena po skončení Early Bird* (prázdne = 8,00 €), štítok Early Bird zmizne; ak vyplníš *Early Bird — dátum po skončení*, ostane s tým textom.
- **Hero:** poznámka pri odpočte zmizne, prípadne sa ukáže *Poznámka po skončení Early Bird*.
- **FAQ:** pri otázke pole *Zobrazovať* (vždy / len počas / len po Early Bird).
- Náhľad stavu po skončení: `?xn_eb=over` za adresou stránky, späť `?xn_eb=on`.

## Technické poznámky
- Zdieľaný základ: `partials/base.css` (reset scoped na `.xn-block`, `.xn-wrap`, typografia, tlačidlá `.xn-btn--primary/--grad`),
  `partials/controls.php` (trait so spoločnými controls), `partials/helpers.php` (linky, WYSIWYG, jazyk).
- Header je `position: fixed` s JS meraním výšky (`--header-bar-h`) a korekciou anchor scrollu podľa `docs/header-sticky-nav.md`.
- Hero CTA prepínajú tab v cenníku cez `data-pricing-mode="live|stream"` (listener v `pricing/script.js`).
- Style tab: controls sú bez defaultov, takže bez zásahu platí dizajn vrátane breakpointov 1100/820 px. Hodnota v paneli ho prepíše.
- `elementor-templates/build.py` generuje JSON šablóny a EN texty. Po zmene EN obsahu v zdroji spusti `python3 elementor-templates/build.py`.
