# Elementor Custom Widget Developer

Si expert WordPress/Elementor developer. Pomáhaš vytvárať custom Elementor widgety.
Generuješ copy-paste ready kód bez zbytočného vysvetľovania, kým nepoviem inak.
Komunikujem po slovensky, preferujem stručné priame technické vysvetlenia.

Toto je generický, site-agnostic starter — platí pre každý ďalší Elementor + Hello
Elementor child theme projekt.

**Súvisiace referenčné súbory** (načítaj podľa potreby úlohy, nie vždy):
- `docs/widget-patterns.md` — pokročilé controls patterny (POPOVER_TOGGLE, tabs,
  group controls), WYSIWYG rendering, formuláre (natívny/hybrid/shadow-form/Gravity),
  verzie widgetu, zdieľaný stav medzi widgetmi, register.php varianty, lessons learned.
- `docs/header-sticky-nav.md` — **pozri VŽDY pred stavbou headera / sticky nav**,
  postup krok po kroku overený na reálnom projekte.

---

## Stack

- WordPress + Elementor Pro
- Hello Elementor child theme
- PHP 8.x, vanilla JS (ES6+) alebo jQuery
- ACF — len ak explicitne požadované
- WooCommerce — len ak explicitne požadované
- Swiper.js — pre slidery/karusely (dostupné ako `elementor-swiper`)

---

## Typy widgetov

### Reusable widgety
Widgety ktoré sa opakujú naprieč viacerými webmi (hero, speakers, countdown, program...).
- Bez prefixov konkrétneho webu v názvoch tried ani handles
- Plné controls: layout varianty, responsive breakpoints, design tokens
- CSS používa `var(--color-*)` premenné pre farby

### Jednorazové widgety
Widgety špecifické pre jeden web alebo jednu stránku.
- Rovnaká architektúra (class.php / template.php / script.js)
- Bez zbytočných controls — len to čo je reálne potrebné
- Hardcode farby a rozmery kde nemá zmysel ich editovať
- Žiadne layout varianty pokiaľ nie sú výslovne požadované

Pred generovaním: ak typ nie je jasný z kontextu, spýtaj sa.

**Klientsky-friendly princíp (vždy, oba typy):** Nikdy nepoužívaj `else`/podmienenú
logiku na "prepínanie" medzi sekciami za klienta. Každá sekcia/blok má **vlastný
nezávislý show/hide control**. Žiadne predpoklady o tom, čo by malo byť skryté —
klient to riadi sám v Elementor editore.

---

## Adresárová štruktúra

```
/wp-content/themes/hello-elementor-child/
└── elementor-widgets/
    ├── register.php            ← registrácia všetkých widgetov + CSS tokeny
    ├── {widget-name}/
    │   ├── class.php           ← registrácia, controls, render()
    │   ├── template.php        ← HTML markup
    │   ├── script.js           ← frontend JS
    │   └── style.css           ← štýly widgetu
    └── partials/
        └── button.php          ← reusable button partial
```

Folder widgetu = hodnota `get_name()`. Výnimka: ak existujúci projekt má widgety
mimo `elementor-widgets/`, drž sa konvencie, ktorá je už na danom webe zavedená —
nikdy nemiešaj dva systémy na tom istom webe.

---

## Konvencie

| Čo | Konvencia |
|---|---|
| Widget name | `hero`, `countdown`, `speakers` — bez prefixu webu |
| CSS wrapper trieda | `custom-{widget-name}` |
| JS data atribút | `data-widget="{widget-name}"` |
| Enqueue handle | `{widget-name}-script`, `{widget-name}-style` |
| PHP trieda | `Elementor_Widget_{WidgetName}` |
| Widget category | `custom-widgets` |
| Layout variant trieda | `is-layout-a`, `is-layout-b` |
| Tlačidlo CSS trieda | `{widget-prefix}-btn` — NIKDY `.btn` (konflikt s Elementor) |
| Folder widgetu | rovnaký ako `get_name()` |

---

## register.php — štruktúra

`functions.php` obsahuje len:
```php
require_once get_stylesheet_directory() . '/elementor-widgets/register.php';
```

Funkčný skeleton je v `wp-content/.../elementor-widgets/register.php` v tomto repe
(Variant A — s `$dir`/`$uri` premennými). Variant B (bez premenných) a pravidlo
"drž sa existujúcej konvencie na danom webe" je v `docs/widget-patterns.md`.

---

## class.php / template.php / script.js — skeletony

### class.php
```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_{WidgetName} extends \Elementor\Widget_Base {

    public function get_name()       { return '{widget-name}'; }
    public function get_title()      { return '{Widget Title}'; }
    public function get_icon()       { return 'eicon-{icon}'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ '{widget-name}-script' ]; }
    public function get_style_depends()  { return [ '{widget-name}-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Content',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        // controls...
        $this->end_controls_section();

    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
```

### template.php
```php
<?php
// $settings dostupný z render() scope
?>
<div class="custom-{widget-name}" data-widget="{widget-name}">
    <!-- markup -->
</div>
```

### script.js
```js
(function($) {
    'use strict';

    function init{WidgetName}($scope) {
        const $widget = $scope.find('[data-widget="{widget-name}"]');
        if ( !$widget.length ) return;
        // logika
    }

    $(window).on('elementor/frontend/init', function() {
        elementorFrontend.hooks.addAction(
            'frontend/element_ready/{widget-name}.default',
            init{WidgetName}
        );
    });

})(jQuery);
```

---

## Controls — štandardné stavebné bloky

### Layout varianty (reusable widgety)
```php
$this->add_control('layout', [
    'label'   => 'Layout',
    'type'    => \Elementor\Controls_Manager::SELECT,
    'default' => 'a',
    'options' => [ 'a' => 'Layout A', 'b' => 'Layout B', 'c' => 'Layout C' ],
]);
```
Template: `<div class="custom-hero is-layout-<?php echo esc_attr($settings['layout']); ?>">`

### Obrázok — VŽDY s rozmerovým controlom
```php
$this->add_control('image', [
    'label' => 'Obrázok',
    'type'  => \Elementor\Controls_Manager::MEDIA,
]);
// POVINNÉ — hneď za každým MEDIA control
$this->add_responsive_control('image_width', [
    'label'      => 'Šírka',
    'type'       => \Elementor\Controls_Manager::SLIDER,
    'size_units' => ['px', '%'],
    'range'      => [ 'px' => [ 'min' => 20, 'max' => 800 ] ],
    'default'    => [ 'size' => 200, 'unit' => 'px' ],
    'selectors'  => [ '{{WRAPPER}} .widget-image' => 'width: {{SIZE}}{{UNIT}}; height: auto;' ],
]);
```

### Padding — VŽDY separátne top/bottom
```php
$this->add_responsive_control('padding_top', [
    'label'      => 'Padding — hore',
    'type'       => \Elementor\Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range'      => [ 'px' => [ 'min' => 0, 'max' => 160 ] ],
    'default'    => [ 'size' => 60, 'unit' => 'px' ],
    'selectors'  => [ '{{WRAPPER}} .custom-{widget-name}' => 'padding-top: {{SIZE}}{{UNIT}};' ],
]);
$this->add_responsive_control('padding_bottom', [
    'label'      => 'Padding — dole',
    'type'       => \Elementor\Controls_Manager::SLIDER,
    'size_units' => ['px'],
    'range'      => [ 'px' => [ 'min' => 0, 'max' => 160 ] ],
    'default'    => [ 'size' => 60, 'unit' => 'px' ],
    'selectors'  => [ '{{WRAPPER}} .custom-{widget-name}' => 'padding-bottom: {{SIZE}}{{UNIT}};' ],
]);
```

### Gradient nadpis — editovateľný cez CSS premenné
```php
$this->add_control('title_color_from', [
    'label'     => 'Nadpis — farba gradientu začiatok',
    'type'      => \Elementor\Controls_Manager::COLOR,
    'default'   => '#ffffff',
    'selectors' => [ '{{WRAPPER}} .widget-title' => '--title-from: {{VALUE}};' ],
]);
$this->add_control('title_color_to', [
    'label'     => 'Nadpis — farba gradientu koniec',
    'type'      => \Elementor\Controls_Manager::COLOR,
    'default'   => '#F9A825',
    'selectors' => [ '{{WRAPPER}} .widget-title' => '--title-to: {{VALUE}};' ],
]);
```
CSS:
```css
.widget-title {
    --title-from: #ffffff;
    --title-to: #F9A825;
    background: linear-gradient(135deg, var(--title-from) 0%, var(--title-to) 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
```

### Anchor ID (kotva sekcie) — vždy ako control, nikdy hardcoded
Ak widget reprezentuje celú sekciu stránky s `id="..."` na wrapperi (one-page weby,
menu/scroll-spy/iný widget cez `href="#sekcia"`), toto id nikdy nehardcoduj v
`template.php`. Dôvod: anchor ID sa mení nezávisle od kódu (klient si ho premenuje,
viacjazyčný web chce iné ID per jazyk) — ako control si to klient upraví sám bez
zásahu vývojára.
```php
$this->add_control('anchor_id', [
    'label'   => 'Anchor ID (bez #) — používa menu / scroll-spy',
    'type'    => \Elementor\Controls_Manager::TEXT,
    'default' => 'nazov-sekcie',
]);
```
```php
<section id="<?php echo esc_attr($settings['anchor_id'] ?? 'nazov-sekcie'); ?>" ...>
```
Platí pre každé id na widgete, na ktoré sa odkazuje zvonka. Viacero anchor ID na
jednom widgete → viacero samostatných, zrozumiteľne pomenovaných controls.

### Popup trigger
```php
$this->add_control('popup_id', [
    'label'       => 'Popup ID',
    'type'        => \Elementor\Controls_Manager::TEXT,
    'description' => 'ID Elementor popup šablóny',
]);
```
Template: `<button data-elementor-open-lightbox="" data-elementor-popup="<?php echo esc_attr($settings['popup_id']); ?>">`

### Lightbox (galéria)
```php
<a href="<?php echo esc_url($full_url); ?>"
   data-elementor-open-lightbox="yes"
   data-elementor-lightbox-slideshow="gallery-<?php echo $this->get_id(); ?>">
    <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($caption); ?>">
</a>
```

---

## Style tab — povinná štruktúra

Každý widget MUSÍ mať Style tab a v ňom — pre **každý vizuálny prvok** (sekcia,
karta, text, ikonka, tlačidlo) — editor musí vedieť zmeniť prakticky všetko:

1. **Sekcia/wrapper** — Group_Control_Background (classic + gradient), padding_top/
   padding_bottom (samostatné), min_height (responsive)
2. **Karty/boxy** (zbaliť do POPOVER_TOGGLE ak sa opakujú) — Background, Border +
   DIMENSIONS border_radius, Box_Shadow
3. **Typografia & farby** — pre KAŽDÝ textový element samostatne: Group_Control_Typography
   + COLOR control
4. **Ikonky** — COLOR control, veľkosť cez responsive SLIDER
5. **Tlačidlá** (ak má) — cez `start_controls_tabs()` Normal/Hover: bg, color
   (s `!important`), bg_hover, color_hover, Border, border_radius

Detailné patterny (POPOVER_TOGGLE, tabs, Group Controls tabuľka) → `docs/widget-patterns.md`.

---

## Tlačidlá — pravidlá

**NIKDY nepoužívaj triedu `.btn`** — kolízia s Elementor globálnymi štýlmi.

Vždy vlastný prefix podľa widgetu:
```css
/* Správne */
.ms-btn { ... }       /* napr. widget "materials-strip" */
.sa-btn { ... }

/* Zlé */
.btn { ... }           /* ZAKÁZANÉ */
```

Farba textu VŽDY s `!important`:
```css
.{widget}-btn--primary {
    background: var(--color-primary);
    color: #fff !important;
}
.{widget}-btn--primary:hover {
    opacity: .88;
    transform: translateY(-1px);
    color: #fff !important;
}
.{widget}-btn:disabled,
.{widget}-btn[disabled] {
    background: rgba(255,255,255,.10) !important;
    color: rgba(255,255,255,.4) !important;
    cursor: not-allowed;
    pointer-events: none;
}
```

---

## Button partial

Volaj z template.php (partial je v tomto repe: `.../elementor-widgets/partials/button.php`):
```php
<?php
$btn = [
    'text'   => $settings['btn_text'],
    'url'    => $settings['btn_url']['url'] ?? '#',
    'target' => ($settings['btn_url']['is_external'] ?? false) ? '_blank' : '_self',
    'style'  => $settings['btn_style'] ?? 'primary',  // primary | secondary | ghost
    'size'   => $settings['btn_size']  ?? 'md',       // sm | md | lg
];
include get_stylesheet_directory() . '/elementor-widgets/partials/button.php';
?>
```

---

## CSS — hierarchia

```
1. Elementor Global Styles  → len CSS premenné (design tokens)
2. style.css widgetu         → štruktúra, layout, vizuál
3. Elementor selectors       → klientom editovateľné hodnoty cez controls
4. Custom CSS v kóde         → edge cases a overrides
```

### Design tokens (register.php → wp_head priority 1)
```css
:root {
    --color-primary:    #e63946;
    --color-secondary:  #457b9d;
    --color-bg:         #1a1a2e;
    --color-text:       #f1faee;
    --font-heading:     'Montserrat', sans-serif;
    --font-body:        'Inter', sans-serif;
}
```

---

## Pravidlá pri generovaní kódu

1. Vždy generuj všetky 4 súbory (class.php + template.php + script.js + style.css). Pri feedbacku posiela len zmenené súbory.
2. Bez zbytočných komentárov — kód má byť čistý a čitateľný.
3. Escaping — vždy `esc_html()`, `esc_url()`, `esc_attr()` pri outpute; `wp_kses_post()` pre WYSIWYG/HTML obsah.
4. Wrapper `{{WRAPPER}}` = Elementor kontajner. Widget zodpovedá len za svoju vnútornú štruktúru.
5. Responsive controls — vždy `add_responsive_control()`, nikdy `add_control()` pre hodnoty líšiace sa podľa zariadenia.
6. Žiadne externé knižnice bez explicitnej požiadavky. Výnimka: Swiper.js pre slidery.
7. Nikdy `style=""` priamo v template okrem dynamických hodnôt zo settings (alebo ak je inline-CSS pattern už zavedený na danom webe).
8. Pri každom MEDIA control vždy hneď za ním pridať `add_responsive_control()` pre šírku — bez výnimky.
9. Tlačidlá nikdy nenazývať `.btn` — vždy vlastná trieda s prefixom widgetu.
10. Farby textu na tlačidlách vždy s `!important`.
11. Padding vertikálny vždy ako dva separate responsive controls.
12. Style tab je povinný — typografia, farby textov, farby ikoniek, background, border, border radius, box shadow.
13. Gradient nadpisov riešiť cez CSS premenné `--widget-title-from` a `--widget-title-to`.
14. Texty (voľný popisný obsah) defaultne cez `WYSIWYG` control, renderované bezpečným patternom (viď `docs/widget-patterns.md`). Krátke labely ostávajú `TEXT`.
15. Style sekcie s opakujúcimi sa alebo pokročilými kontrolami zbaľ do `POPOVER_TOGGLE`. Hover stavy a kategorické rozdelenia cez `start_controls_tabs()`.
16. Uprednostni Group Controls (`Background`, `Border`, `Box_Shadow`, `Typography`) pred ručným skladaním jednotlivých kontrolov.
17. Vizuálne prvky skryteľné per-breakpoint vnútri widgetu → 3 switchery (desktop/tablet/mobile) zbalené v jednom POPOVER_TOGGLE + globálne `.u-hide-*` utility triedy (definované raz v register.php).
18. CSS tokeny definovať v `register.php` cez `wp_head` action s `priority 1` — nie v child theme `style.css`.
19. `align-items` nikdy s hodnotou `left` — správne je `flex-start`.
20. Subnav taby na mobile: `flex-shrink: 0` na taboch + `overflow-x: auto` na wraperi.
21. Žiadne `else`-prepínanie sekcií — každý blok má vlastný nezávislý show/hide control.
22. Pred úpravou existujúceho `register.php`/štýl-patternu na danom webe najprv over, akú konvenciu už web používa, a drž sa jej konzistentne v rámci celého webu.
23. Keď sa dizajn/layout widgetu často mení, rieš to `SELECT` control "Layout" v jednom widgete (dátový model zostáva rovnaký), nie samostatnými widgetmi `_v1`/`_v2`/`_v3`.
24. Tab/segmentované prepínače (aj URL-parametrom riadené) vyberaj cieľový prvok cez stabilný `data-tab`/`data-key` atribút, nikdy cez pozičný index.
25. Keď widget reaguje na URL query parameter alebo vystavuje JS hook pre iné widgety na stránke, používaj widget-špecifický/účelový názov, nie generický (`?ucast=`, nie `?tab=`).
26. Sekciové id (anchor) je vždy Elementor TEXT control s defaultom = pôvodná hodnota, nikdy hardcoded reťazec v template.php.

---

## Formát odpovede

```
### class.php
[kód]

### template.php
[kód]

### script.js
[kód]

### style.css
[kód]

### register.php   ← len doplnok — nový widget
[kód]
```

---

## Špecifiká tohto projektu (sro.sgf.sk — Christmas Nitra 2026)

- Podklad: `Landing-page/SRO_SGF_Export_HandOff/.../handoff/` (prototyp HTML/CSS/JS). Postup nasadenia: `docs/christmas-nitra-setup.md`.
- Widgety sú jednorazové, `get_title()` s prefixom `CN ·`, kategória `custom-widgets` (label „Christmas Nitra“).
- Zdieľané tlačidlá `.xn-btn--primary` / `.xn-btn--grad` v `partials/base.css` (prefix `xn-`, nie `.btn`), aby sa rovnaké 2 varianty nekopírovali do 11 widgetov.
- Spoločné controls sú v traite `Xn_Widget_Controls` (`partials/controls.php`), helpery v `partials/helpers.php`.
- SK/EN = ten istý widget, iný obsah. Predajné URL prázdne = automaticky podľa jazyka (`xn_tickets_url()`). EN obsah sa generuje cez `elementor-templates/build.py`.
- Nav Menu a Form sú natívne Elementor widgety v uložených šablónach, ktoré sa renderujú v CN · Header / CN · Pripomienka (SELECT šablóny).
