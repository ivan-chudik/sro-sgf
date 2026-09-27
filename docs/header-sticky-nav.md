# Header / Sticky Nav Playbook

Vznikol po reálnom (bolestivom) ladení headera na jednom z projektov. Cieľ: aby
ďalší header na ďalšom projekte išiel v jednom prechode, nie cez 15 kôl "vráť to,
teraz je to inak rozbité".

**Pozri tento súbor VŽDY pred stavbou headera / sticky nav** — nie je to zoznam
opráv, je to poradie krokov, ktorým treba header stavať hneď na prvý pokus.

---

## ČASŤ 1 — Header / sticky nav: postup od začiatku

### Krok 0 — Prijmi vopred: header je vo vnorenejšom kontexte než hocijaká iná sekcia

Theme Builder header nie je "widget na stránke", je to
`<header data-elementor-type="header">` → Elementor Container
(`e-con-full e-flex e-con`) → `elementor-widget-container` → náš widget. Táto vrstva
navyše je presne to, čo robí `position:sticky` a jednoduché box-height úvahy
nespoľahlivými. Počítaj s tým vopred, nenechaj sa tým prekvapiť v polovici buildu.

### Krok 1 — Pripnutý (sticky/fixed) riadok rovno stav ako `position: fixed`

Nikdy nezačínaj s `position: sticky` na headeri vo Theme Builderi. Sticky sa
spoľahlivo pokazí, ak má čo i len jeden predok nastavený `overflow` inak než
`visible` — a to sa nedá vopred vylúčiť. Choď rovno na `fixed`:

```css
.w-header__bar{
  position: fixed; top: 0; left: 0; right: 0; z-index: 20;
  background: rgba(18,35,71,0.92); backdrop-filter: blur(8px);
}
.w-header{ padding-top: var(--header-bar-h, 71px); } /* rezervuje miesto v toku */
```

```js
function setBarHeight(){
  var bar = document.querySelector('.w-header__bar');
  if (bar) document.documentElement.style.setProperty('--header-bar-h', bar.offsetHeight + 'px');
}
setBarHeight();
window.addEventListener('resize', setBarHeight);
window.addEventListener('load', setBarHeight);
if (document.fonts?.ready) document.fonts.ready.then(setBarHeight); // výška sa vie zmeniť po doťahaní fontov
```

`71px` fallback v `var()` je len núdzová hodnota pred prvým JS výpočtom (FOUC
poistka), nikdy sa naň nespoliehaj ako na reálne číslo.

### Krok 2 — Ak má header sekundárny pás (subnav/breadcrumbs), rozhodni HNEĎ ako sa má správať

Dve legitímne možnosti, rozhodni sa vopred (spýtaj sa klienta, ak to nie je jasné
zo zadania):

**A) Celý header (nav + subnav) je pripnutý spolu, vždy viditeľný.**
Jednoduchšie — jeden `.w-header__bar` obaľuje oboje, žiadne extra JS.

**B) Subnav viditeľný len navrchu stránky, mizne pri scrolli, nav riadok zostáva pripnutý.**
Subnav musí byť **mimo** fixed baru, v bežnom toku dokumentu:

```html
<header class="w-header">
  <div class="w-header__bar">...nav riadok...</div>  <!-- fixed -->
  <div class="w-subnav">...</div>                     <!-- normálny tok -->
</header>
```

V tomto prípade subnav prirodzene zmizne po pár desiatkach px scrollu a znova sa
objaví len po návrate na úplný vrch — presne toto správanie sa najčastejšie pýta
klient, keď povie "nech mi zostane menu vždy na očiach".

### Krok 3 — `scroll-padding-top` NESTAČÍ samo osebe, potrebuješ aj JS korekciu

Fixed header prekrýva topovú časť cieľovej sekcie pri kliku na `#kotva`.
`scroll-padding-top` na `html` je správny základ, ale nespoľahni sa naň jediného —
v praxi sa správal nekonzistentne (priamy vstup s `#hash` v URL vs. klik na odkaz
počas prehliadania). Rieš to manuálnym výpočtom, v zdieľanom JS (nie v headeri —
týka sa každého odkazu na stránke, aj z pätičky, aj cross-section CTA):

```js
function scrollToHashTarget(hash, behavior) {
  if (!hash) return;
  var target = document.querySelector(hash);
  if (!target) return;
  var barH = parseFloat(getComputedStyle(document.documentElement).getPropertyValue("--header-bar-h")) || 71;
  var y = target.getBoundingClientRect().top + window.pageYOffset - barH - 8;
  window.scrollTo({ top: Math.max(0, y), behavior: behavior || "auto" });
}

// Klik na odkaz smerujúci na kotvu tejto istej stránky (menu, pätička, cross-section CTA)
document.addEventListener("click", function (e) {
  var a = e.target.closest("a[href]");
  if (!a) return;
  var url;
  try { url = new URL(a.getAttribute("href"), window.location.href); } catch (err) { return; }
  if (!url.hash || url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
  e.preventDefault();
  history.pushState(null, "", url.hash);
  scrollToHashTarget(url.hash, "smooth");
});

// Priamy vstup na stránku s #hash v URL — po plnom naloadovaní (Elementor lazy-loaduje
// sekcie pod fold-om, takže skok pri "DOMContentLoaded" môže pristáť "skôr", než má)
if (window.location.hash) {
  window.addEventListener("load", function () {
    setTimeout(function () { scrollToHashTarget(window.location.hash); }, 300);
  });
}
```

Použi `new URL()` na parsovanie hrefu (nikdy `href.indexOf('#')` ani
`href.charAt(0)==='#'` naslepo) — porovnaj `origin` aj `pathname`, nech sa toto
nenaviaže omylom na odkaz smerujúci na kotvu inej stránky.

### Krok 4 — WP adminbar kompenzácia (inak to bude vyzerať rozbité LEN prihlásenému adminovi)

```css
body.admin-bar .w-header__bar{ top: 32px; }
@media screen and (max-width: 782px){
  body.admin-bar .w-header__bar{ top: 46px; }
}
```

`body.admin-bar` pridáva WordPress sám automaticky pri prihlásenom používateľovi s
adminbarom. Bez tohto pravidla WP núti `html{margin-top:32px}` pre bežný obsah, ale
`fixed` prvky (náš bar) to ignorujú — vznikne 32px medzera veľkosti adminbaru,
viditeľná len tebe/klientovi ako adminovi. **Testuj vždy aj v inkognito okne** — ak
vyzerá inak než prihlásený, toto je prvá vec na kontrolu.

### Krok 5 — Bordery/čiary: daj ich na FULL-WIDTH element, nikdy na max-width wrapper

Ak má čiara siahať od kraja po kraj, musí byť na elemente **bez** `max-width` — nie
na vnútornom centrovanom wrapperi (`max-width:1280px`), inak sa čiara zmrští na
šírku obsahu namiesto celého viewportu:

```css
.w-subnav{ border-bottom: 1px solid var(--cs-rule); }              /* full-width */
.w-subnav__inner{ max-width: var(--cs-content-max); margin: 0 auto; padding: 10px 32px; }
```

Nespoliehaj sa na to, že border na RODIČOVSKOM elemente (napr. `.w-header`
obaľujúcom fixed bar + subnav) "vypočíta" správnu výšku súčtom detí — kombinácia
fixed dieťaťa (nulová výška v toku) + flow dieťaťa je krehká. Daj border priamo,
explicitne, na konkrétny viditeľný element.

### Krok 6 — Logo/brand odkaz → `home_url('/')`, nikdy `#top` alebo kotva

```php
<a class="w-brand" href="<?php echo esc_url( home_url('/') ); ?>">
```

Logo má byť normálny odkaz na homepage URL, nie same-page anchor scroll. Toto
odstraňuje potrebu riešiť "aké anchor ID má mať prvá sekcia" naprieč budúcimi
podstránkami. Ak má byť klik na logo jediný klikateľný element, obaľ CELÝ blok
(mark + wordmark + text) jedným `<a>` — **neskladaj to na `<div>` + vnorený `<a>`
len okolo obrázkov s textom mimo**. Rozbíja to flex layout (text mimo `<a>` stráca
`display:flex` kontext, baseline-alignment kolízie s obrázkami vedľa).

### Krok 7 — Mobilné menu (fullscreen overlay): pozor na z-index vnoreného vs. súrodenca

Burger tlačidlo je vnútri `.w-header__bar`. Fullscreen mobilný panel
(`.w-mobile-panel`) je jeho **súrodenec**, s vyšším z-indexom. Keď sa panel otvorí,
prekryje aj bar aj burger — vysoký z-index na burgeri samotnom nepomôže, lebo sa
porovnáva len v rámci vlastného stacking contextu rodiča, nie globálne proti
súrodencovi. Rieš zdvihnutím z-indexu **rodiča** (bar), keď je menu otvorené:

```css
html.w-menu-open .w-header__bar{ z-index: 110; } /* nad panelom, ktorý má z-index:100 */
```

### Krok 8 — Natívne `<button>` prvky (burger) dedia default hover z témy

Hello Elementor (a iné témy) majú vlastný `button:hover{ background-color:#e36; color:#fff; }`
alebo podobný reset s vyššou špecifickosťou než obyčajná trieda bez `:hover`
varianty. Každý custom `<button>` potrebuje **vlastný explicitný `:hover`/`:focus`
prepis** (background aj color):

```css
.w-burger:hover, .w-burger:focus{ background: none; }
```

Rovnaký princíp platí pre `a:hover` — Elementorov generický `a:hover{color:#336;}`
vie prebiť farbu vlastného tlačidla/odkazu.

### Krok 9 — Test vždy pri `max-width` tokene stránky, nie "podľa monitora"

`.w-header__inner{ max-width: 1280px; }` znamená, že header má vždy rovnako veľa
priestoru nezávisle od veľkosti fyzického monitora. Ak sa nav+brand+CTA nezmestia
na 1280px, prejaví sa to identicky na notebooku aj na 3000px monitore. Otestuj v
DevTools na presnú šírku max-width tokenu (Responsive mode, zadaj presné číslo).
Keď niečo nesedí, over v Elements → Computed skutočnú šírku `.w-header__inner` a
jeho detí — porovnaj čísla, nehádaj naslepo.

---

## ČASŤ 2 — Všeobecné poznatky (netýkajú sa len headera)

### CSS shorthand ticho prepisuje longhand z inej triedy na tom istom elemente

Keď je na jednom elemente kombinácia dvoch tried a KAŽDÁ rieši iný "axis" paddingu
(napr. `.op-wrap{padding:0 32px}` horizontálny, `.op-body{padding-top:40px;padding-bottom:96px}`
vertikálny), mobilná úprava cez shorthand na jednej z nich (`padding: 0 20px`) ticho
vynuluje aj vlastnosti druhej triedy. Keď meníš len jednu os pri elemente, kde je
vertikálny padding riadený INOU triedou, vždy longhand (`padding-left`/`padding-right`),
nikdy shorthand `padding`.

### `flex-shrink: 0` + zalomiteľný text = tichý horizontálny pretek na mobile

Ak má flex item text, ktorý sa má zalomiť pri užšom viewporte, nedávaj mu
`flex-shrink:0` bez zároveň nastaveného `flex-basis`/šírky — inak sa pri zúžení
nezalomí, len vytečie von.

### `white-space: nowrap` na tlačidlách s dlhým lokalizovaným textom

Dizajnové systémy dodané v angličtine často nepočítajú s dĺžkou slovenského textu.
`.cs-btn{white-space:nowrap}` + dlhý SK text spoľahlivo spôsobí horizontálny pretek
na mobile. Vždy pridaj mobilný breakpoint prepínajúci na `white-space:normal` +
`flex-direction:column`.

### Dodané CSS zväčša testované len od ~900px vyššie, nič nižšie

Pri každom novom widgete/sekcii preventívne skontroluj: `grid-template-columns`,
`white-space:nowrap`, `flex-shrink:0` v kombinácii so zalomiteľným obsahom, a
horizontal padding pri ≤480px.

### WYSIWYG control zabalený do vlastného `<p>` v template.php rozbíja layout

Elementor WYSIWYG control si sám obalí hodnotu do `<p>...</p>`. Ak template.php
výstup ešte raz obalí do `<p>`, vznikne fantómový prázdny `<p></p>` navyše (auto-closing
vnoreného `<p>` a osirelý closing tag). V bežnom bloku to vizuálne nevadí, vo
flex/grid layoute sa stane tretím neviditeľným "stĺpcom". **Vždy `<div>`, nikdy
`<p>`** okolo WYSIWYG výstupu.

### Elementor lazy-loading (`e-lazyloaded`) ovplyvňuje anchor scroll timing

Ak stránka príde s `#hash` v URL, natívny skok prehliadača sa môže odohrať skôr, než
sa dorenderujú sekcie pod cieľom. Rieši to Krok 3 vyššie (re-scroll po
`window.onload` + krátky delay).

### DevTools box-model overlay vie vyzerať ako reálny CSS bug

Farebné zvýraznenie content/padding/margin boxu pri vybranom elemente v Elements
table je vizuálny artefakt prehliadača. Než začneš opravovať "bug" zo screenshotu,
over či nie je vybraný element v DevTools na tom istom screenshote.

### Testuj zmeny v skutočne nezávislom prostredí, nie len v Elementor editore

Elementor editor canvas (iframe) sa vie správať inak než reálny front-end — JS
timing (IntersectionObserver) aj šírka canvasu (užšia kvôli bočným panelom). Pri
sporných prípadoch over vždy aj cez Preview/živý front-end, ideálne v inkognito okne.

---

## Zhrnutie — checklist pri stavaní ĎALŠIEHO headera

- [ ] Fixed, nie sticky, od prvého riadku kódu
- [ ] JS meria výšku bar-u → CSS premenná → padding-top rezerva
- [ ] Rozhodnuté vopred: subnav pripnutý spolu s nav, alebo mizne pri scrolli?
- [ ] Manuálna JS korekcia anchor scrollu (klik + hashchange + load s delay)
- [ ] `body.admin-bar` kompenzácia
- [ ] Bordery na full-width elemente, nie na max-width wrapperi
- [ ] Logo → `home_url('/')`, nie `#top`
- [ ] Mobilný overlay: z-index na rodičovi pri otvorení, nie len na tlačidle
- [ ] Explicitný `:hover`/`:focus` na každom custom `<button>`
- [ ] Testované na presnú `max-width` hodnotu, nie "na oko"
- [ ] Testované aj v inkognito okne (adminbar, rozšírenia)
