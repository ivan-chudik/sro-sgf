# Widget Patterns — pokročilé vzory

Referenčná dokumentácia pre custom Elementor widget systém. Generická, site-agnostic.
Toto načítaj len keď úloha reálne potrebuje niektorý z týchto vzorov — `CLAUDE.md`
pokrýva bežný denný use-case sám.

---

## Verzie widgetu — layout variant vs. nový widget

Keď sa dizajn/layout widgetu často mení (klient si to rozmyslí, testuje varianty),
**nerob samostatné widgety `price_v1`, `price_v2`, `price_v3`...** Pri prepnutí
widget-typu v Elementore sa všetok doteraz vyplnený obsah stratí a starý widget-typ
zostáva v paneli ako "mŕtva" história.

**Namiesto toho: jeden widget, jeden dátový model, a `SELECT` control "Layout"
navyše**, ktorý mení len render/CSS vetvu, nie štruktúru dát:

```php
$this->add_control('opts_layout', [
    'label'   => 'Layout kariet',
    'type'    => Controls_Manager::SELECT,
    'options' => [ 'tabs' => 'Prepínač (taby)', 'grid' => 'Vedľa seba' ],
    'default' => 'tabs',
    'condition' => [ 'opts_show' => 'yes' ],
]);
```

V `template.php` sa podľa hodnoty len rozhoduje: renderovať tab-bar áno/nie, `hidden`
atribút na kartách áno/nie, aká CSS trieda ide na wrapper (`is-layout-tabs` /
`is-layout-grid`). Obsah v controls (repeatery, texty, ceny) ostáva rovnaký bez
ohľadu na aktívny layout.

**Kedy áno nový widget:** keď sa mení samotný *dátový model* — iné polia, iná
štruktúra dát, nie len iné zobrazenie tých istých dát. Vtedy sa starý widget naozaj
vyradí z `register.php`, nenecháva sa "na potom".

---

## Zdieľaný stav medzi widgetmi na tej istej stránke

Nezávisle vyvíjané widgety na jednej stránke môžu nevedomky zdieľať globálny
browser-level stav — najčastejšie **URL query parametre** (napr. `?tab=`), ale
rovnaký problém spôsobí aj neprefixovaný custom JS event name alebo neprefixovaná
CSS trieda použitá ako JS hook.

Typický scenár: widget A aj widget B pri načítaní stránky čítajú `?tab=` a po
spracovaní ho zmažú z URL cez `history.replaceState`. Ktorý je na stránke vyššie
(jeho `element_ready` sa spustí skôr), ten parameter "zjedol" prv — druhý widget
spadne do default vetvy, aj keď parameter v URL reálne bol.

**Pravidlo:** widget-špecifický/účelovo pomenovaný parameter (`?ucast=` pre cenové
karty vs. `?tab=` pre kontakt), nie generický `?tab=`/`?mode=`/`?view=`. Pri JS
hookoch medzi widgetmi uprednostni dedikovaný `data-*` atribút (napr.
`data-ct-trigger="hybrid"`) pred väzbou na vizuálnu CSS triedu.

(ID kolízie medzi inštanciami widgetov nie sú reálne riziko — `get_id()` je
unikátne per inštancia.)

---

## register.php — dva varianty

**Variant A — s `$dir`/`$uri` premennými (predvolený, viď funkčný skeleton v repe):**

```php
$dir = get_stylesheet_directory()     . '/elementor-widgets/';
$uri = get_stylesheet_directory_uri() . '/elementor-widgets/';

add_action('wp_enqueue_scripts', function() use ($dir, $uri) {
    wp_register_script('hero-script', $uri . 'hero/script.js', ['jquery'], filemtime($dir . 'hero/script.js'), true);
    wp_register_style('hero-style', $uri . 'hero/style.css', [], filemtime($dir . 'hero/style.css'));
});

add_action('elementor/widgets/register', function($widgets_manager) use ($dir) {
    require_once $dir . 'hero/class.php';
    $widgets_manager->register( new Elementor_Widget_Hero() );
});
```

**Variant B — bez `$dir`/`$uri` premenných** (niektoré existujúce projekty majú
`register.php` postavený inak — vtedy sa drž ich patternu, nezavádzaj `$dir`/`$uri`
naviac):

```php
wp_register_script(
    'hero-script',
    get_stylesheet_directory_uri() . '/elementor-widgets/hero/script.js',
    ['jquery'],
    filemtime( get_stylesheet_directory() . '/elementor-widgets/hero/script.js' ),
    true
);

require_once __DIR__ . '/hero/class.php';
$widgets_manager->register( new Elementor_Widget_Hero() );
```

> Pred úpravou `register.php` na existujúcom webe vždy najprv pozri ako je už
> napísaný, a drž sa toho istého patternu pre celý súbor.

---

## Controls UX Framework — povinné pre každý widget

Cieľ: panel v Elementor editore nesmie pôsobiť ako "nahádzané seno" — dlhý rovný
zoznam 20 kontrolov naťahujúci sa do nekonečna. Kontroly sa **zoskupujú, skladajú
a skrývajú**.

### 1. HEADING — vizuálny rozdeľovač
```php
$this->add_control('heading_card_style', [
    'label'     => 'Karta — vzhľad',
    'type'      => \Elementor\Controls_Manager::HEADING,
    'separator' => 'before',
]);
```

### 2. POPOVER_TOGGLE — kompaktné zoskupenie súvisiacich kontrolov
Hlavný nástroj proti "naťahovaniu panelu na výšku". Skupina kontrolov (tieň, border,
padding karty) sa zbalí za jedno tlačidlo:

```php
$this->add_control('card_style_toggle', [
    'label'        => 'Štýl karty',
    'type'         => \Elementor\Controls_Manager::POPOVER_TOGGLE,
    'label_off'    => 'Default',
    'label_on'     => 'Custom',
    'return_value' => 'yes',
]);
$this->start_popover();

    $this->add_group_control( \Elementor\Group_Control_Background::get_type(), [
        'name'     => 'card_bg',
        'types'    => ['classic', 'gradient'],
        'selector' => '{{WRAPPER}} .widget-card',
    ]);

    $this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
        'name'     => 'card_border',
        'selector' => '{{WRAPPER}} .widget-card',
    ]);

    $this->add_responsive_control('card_border_radius', [
        'label'      => 'Border radius',
        'type'       => \Elementor\Controls_Manager::DIMENSIONS,
        'size_units' => ['px', '%'],
        'selectors'  => [
            '{{WRAPPER}} .widget-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
    ]);

    $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
        'name'     => 'card_shadow',
        'selector' => '{{WRAPPER}} .widget-card',
    ]);

$this->end_popover();
```

Použi POPOVER_TOGGLE všade tam, kde sa rovnaký blok kontrolov opakuje pre viac
prvkov, alebo kde ide o "pokročilé" nastavenie, ktoré sa mení zriedka.

### 3. TABS — Normal / Hover (a iné kategorické rozdelenia)
```php
$this->start_controls_tabs('tabs_button_style');

    $this->start_controls_tab('tab_button_normal', ['label' => 'Normal']);
        $this->add_control('button_bg', [
            'label'     => 'Pozadie',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .widget-btn' => 'background: {{VALUE}};'],
        ]);
        $this->add_control('button_color', [
            'label'     => 'Text',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .widget-btn' => 'color: {{VALUE}} !important;'],
        ]);
    $this->end_controls_tab();

    $this->start_controls_tab('tab_button_hover', ['label' => 'Hover']);
        $this->add_control('button_bg_hover', [
            'label'     => 'Pozadie (hover)',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .widget-btn:hover' => 'background: {{VALUE}};'],
        ]);
        $this->add_control('button_color_hover', [
            'label'     => 'Text (hover)',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .widget-btn:hover' => 'color: {{VALUE}} !important;'],
        ]);
    $this->end_controls_tab();

$this->end_controls_tabs();
```

`start_controls_tabs()` sa dá použiť aj kategoricky — napr. rozdeliť nabitú Style
sekciu na taby "Pozadie" / "Border" / "Typografia" namiesto jedného dlhého zoznamu.

### 4. `condition` — kontrola sa zobrazí len keď dáva zmysel
```php
$this->add_control('show_button', [
    'label'   => 'Zobraziť tlačidlo',
    'type'    => \Elementor\Controls_Manager::SWITCHER,
    'default' => 'yes',
]);
$this->add_control('btn_text', [
    'label'     => 'Text tlačidla',
    'type'      => \Elementor\Controls_Manager::TEXT,
    'condition' => ['show_button' => 'yes'],
]);
```

### 5. Group Controls — vždy uprednostniť pred manuálnymi kontrolami

| Group Control | Nahrádza ručné | Použitie |
|---|---|---|
| `Group_Control_Typography` | font-family, size, weight, line-height, letter-spacing | každý textový element |
| `Group_Control_Background` | farba/gradient/obrázok pozadia | sekcie, karty |
| `Group_Control_Border` | border-style, width, color | karty, inputy, boxy |
| `Group_Control_Box_Shadow` | shadow color/blur/spread/position | karty, tlačidlá |
| `Group_Control_Image_Size` | šírka/výška/crop pre MEDIA control | obrázky s viacerými veľkosťami |

Border radius **nie je** súčasť `Group_Control_Border` — vždy samostatný
`DIMENSIONS` control hneď za ním.

### 6. Responsive — bez výnimky `add_responsive_control()`
Elementor pri `add_responsive_control()` natívne ukladá a zobrazuje hodnotu pre
aktuálny breakpoint — prepínanie Desktop/Tablet/Mobile v top bare funguje
automaticky, netreba na to vlastnú JS logiku. Ak sa objaví "desynchronizácia" medzi
breakpointmi, najčastejšia príčina je `add_control()` namiesto `add_responsive_control()`.

### 7. Viditeľnosť konkrétneho prvku per breakpoint
Elementor natívne skryje celý widget per breakpoint, ale nie prvky *vnútri*
template. Formalizovaný pattern — 3 switchery v jednom POPOVER_TOGGLE:

```php
$this->add_control('box_visibility_toggle', [
    'label'        => 'Viditeľnosť',
    'type'         => \Elementor\Controls_Manager::POPOVER_TOGGLE,
    'label_off'    => 'Vždy viditeľné',
    'label_on'     => 'Custom',
    'return_value' => 'yes',
]);
$this->start_popover();
    $this->add_control('box_hide_desktop', ['label' => 'Skryť na desktope', 'type' => \Elementor\Controls_Manager::SWITCHER]);
    $this->add_control('box_hide_tablet',  ['label' => 'Skryť na tablete',  'type' => \Elementor\Controls_Manager::SWITCHER]);
    $this->add_control('box_hide_mobile',  ['label' => 'Skryť na mobile',   'type' => \Elementor\Controls_Manager::SWITCHER]);
$this->end_popover();
```

```php
<?php
$box_classes = ['widget-box'];
if (($settings['box_hide_desktop'] ?? '') === 'yes') $box_classes[] = 'u-hide-desktop';
if (($settings['box_hide_tablet']  ?? '') === 'yes') $box_classes[] = 'u-hide-tablet';
if (($settings['box_hide_mobile']  ?? '') === 'yes') $box_classes[] = 'u-hide-mobile';
?>
<div class="<?php echo esc_attr(implode(' ', $box_classes)); ?>">...</div>
```

CSS utility triedy (`.u-hide-desktop/tablet/mobile`) sa definujú **raz globálne**
v `register.php`, nie opakovane v každom widgete.

---

## Texty — WYSIWYG ako predvolený typ

Pre akýkoľvek text dlhší než krátky label/nadpis používaj **WYSIWYG** control (nie
TEXT/TEXTAREA), nech klient vie sám formátovať bez zásahu vývojára:

```php
$this->add_control('popis', [
    'label'   => 'Popis',
    'type'    => \Elementor\Controls_Manager::WYSIWYG,
    'default' => '',
]);
```

**Bezpečné renderovanie (povinný pattern — rieši prázdne `<p></p>` rozbíjajúce layout):**

```php
<?php
$raw   = $settings['popis'] ?? '';
$clean = preg_replace('/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $raw);
$clean = trim($clean);
if ($clean !== '') : ?>
    <div class="widget-text"><?php echo wp_kses_post($clean); ?></div>
<?php endif; ?>
```

Pravidlá:
- Výstup vždy do `<div>`, nikdy do `<p>` — WYSIWYG sám pridáva `<p>` dovnútra,
  dvojité zalomenie do `<p><p>...</p></p>` rozbíja layout.
- Vždy `wp_kses_post()`, nie `esc_html()` — WYSIWYG obsah má ostať HTML.
- Podmienka na zobrazenie na `trim()`-nutom obsahu *po* odstránení prázdnych `<p>`,
  nie na surovom `$raw`.
- Krátke jednoriadkové polia ostávajú `TEXT`. Polia s manuálnym `<br>` môžu ostať
  `TEXTAREA`. WYSIWYG je default pre voľný popisný text.
- Pri poliach s jednoduchými inline značkami (`<b>`, `<a>`, `<br>`), kde je plný
  WYSIWYG toolbar zbytočný, alternatíva: `TEXTAREA` + `wp_kses($val, ['b'=>[], 'a'=>['href'=>[],'target'=>[]], 'br'=>[]])`.

---

## Formuláre — natívny Elementor Form vs custom widget vs Gravity Forms

"Spravíme custom formulár" nie je default voľba — je to voľba s cenou (spam,
stratené submissions, údržba).

| Situácia | Riešenie |
|---|---|
| Bežné polia, jedna akcia (email/webhook) | **Natívny Elementor Form widget** — reCAPTCHA a submissions storage z krabice (storage vyžaduje Advanced+ licenciu) |
| Bežné polia, špecifický vizuál okolo | Natívny Form **obalený** custom widgetmi — wrap-top/wrap-bottom pattern |
| UX ktorý Elementor Form nevie (dátumový picker s countrom, deep-linking cez URL) | **Custom hybrid widget** — POVINNE so safeguardmi nižšie |
| Multi-step, podmienená logika, file uploads, platby, CRM/Zapier | **Gravity Forms** |
| Plná sloboda UX, ale storage/spam/notifikácie chceš mať vyriešené bez vlastného BE handlera | **"Shadow form" pattern** (nižšie) |

### "Shadow form" pattern — custom FE napojený na natívny Elementor BE

Postav vlastnú UI úplne nezávisle, ale namiesto vlastného `admin-post.php` handlera
napoj submit na **skutočný, len vizuálne skrytý Elementor Form widget** na tej istej
stránke. Elementor tak rieši nonce, server-side validáciu, spam ochranu, uloženie
do `wp_e_submissions` (Advanced+), email/webhook akcie.

1. Pridaj Elementor Form widget s poľami zodpovedajúcimi dátam z custom UI. Každému
   poľu nastav explicitný **Field ID** (nie auto-generovaný hash).
2. Skry ho vizuálne — `opacity:0; position:absolute; pointer-events:none;`
   (nie `display:none` — problém so submitom v niektorých browseroch).
3. Custom UI validuje vlastnou JS logikou, klient nikdy nevidí natívne Elementor chyby.
4. Po úspešnej validácii JS nastaví hodnoty do skrytých polí (`form_fields[field_id]`).
5. Spusti natívny submit — `.click()` na skryté submit tlačidlo.
6. Sleduj výsledok cez `MutationObserver` na success/error správu v DOM skrytého formulára.

```js
function submitViaShadowForm(data) {
    const $shadowForm = $('.custom-{widget}__shadow-form');

    $shadowForm.find('[name="form_fields[meno]"]').val(data.meno);
    $shadowForm.find('[name="form_fields[email]"]').val(data.email);
    $shadowForm.find('[name="form_fields[suhlas]"]').prop('checked', true);

    $shadowForm.find('button[type="submit"]')[0].click();
}

const observer = new MutationObserver(() => {
    if ($shadowForm.find('.elementor-message-success').length) {
        showCustomSuccessUI();
        observer.disconnect();
    }
    if ($shadowForm.find('.elementor-message-danger').length) {
        showCustomErrorUI();
    }
});
observer.observe($shadowForm[0], { childList: true, subtree: true });
```

**Nerieši:** SMTP deliverabilitu (SMTP plugin je stále potrebný), explicitný rate
limiting, Submissions admin má dáta ploché/textové.

### Custom hybrid widget bez napojenia na natívny BE — povinné safeguardy

1. **Nonce / CSRF** — `wp_nonce_field()` + `check_admin_referer()`.
2. **Honeypot** minimálne; pri vyššej záťaži reCAPTCHA v3 / Cloudflare Turnstile.
3. **Submission sa VŽDY uloží do DB pred pokusom o mail** — vlastný custom post type.
4. **Rate limiting** — transient throttle podľa IP.
5. **Validácia vždy aj na serveri**, nezávisle od JS.
6. **SMTP plugin namiesto holého `wp_mail()`** — bez neho maily padajú do spamu/nechodí.

Ak tieto body nevieš/nechceš dodržať, choď radšej cestou shadow form patternu alebo
Gravity Forms.

### Gravity Forms + Elementor
Vlastný widget v Elementor paneli, alebo shortcode `[gravityform id="X"]` v natívnom
Shortcode widgete. Vizuálny obal rieš wrap-top/wrap-bottom patternom, štýlovanie cez
`.gform_wrapper ...` s `!important` kde treba. Orientačná cena (overiť pri reálnom
rozhodovaní): Basic ~$59/rok (1 web), Pro ~$159/rok (3 weby), Elite ~$259/rok (neobmedzene).

---

## Elementor šablóna v custom widgete (login/registrácia)

```php
$this->add_control('form_template_id', [
    'label'   => 'Vyber šablónu s formulárom',
    'type'    => \Elementor\Controls_Manager::SELECT,
    'options' => $this->get_elementor_templates(),
    'default' => '',
]);

private function get_elementor_templates() {
    $options = [ '' => '— vyber šablónu —' ];
    $templates = get_posts(['post_type' => 'elementor_library', 'posts_per_page' => -1, 'post_status' => 'publish']);
    foreach ( $templates as $t ) { $options[ $t->ID ] = $t->post_title; }
    return $options;
}

// template.php
$template_id = (int) ($settings['form_template_id'] ?? 0);
if ( $template_id && class_exists('\Elementor\Plugin') ) {
    echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($template_id);
}
```

Ak má formulár vlastné texty navôkol, rozdeľ to na samostatné widgety okolo
natívneho formulára (wrap-top / natívny Form / wrap-bottom) namiesto shortcode-vkladania.

---

## Swiper slider — enqueue pattern

```php
public function get_script_depends() {
    return [ 'elementor-swiper', '{widget-name}-script' ];
}
```
```js
const swiper = new Swiper($widget.find('.swiper')[0], {
    slidesPerView: 1,
    spaceBetween: 24,
    breakpoints: { 768: { slidesPerView: 2 }, 1024: { slidesPerView: 3 } },
    pagination: { el: '.swiper-pagination', clickable: true },
    navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' },
});
```

---

## Cross-domain CTA / tab-trigger pattern

Keď tlačidlo na inej (sub)doméne má otvoriť konkrétny tab/stav widgetu na cieľovej
stránke — klik sa nedá zachytiť JS-om (plný reload), preto cez URL parameter.

**1. Zdrojové tlačidlo** smeruje na: `https://cielova-domena.sk/stranka/?tab=hybrid#sekcia`

**2. Cieľový widget — script.js** pri načítaní:
```js
const params = new URLSearchParams(window.location.search);
if (params.get('tab') === 'hybrid') {
    setMode('hybrid');
    params.delete('tab');
    const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
    window.history.replaceState({}, '', clean);
    document.querySelector('[data-widget="..."]')?.scrollIntoView({ behavior: 'smooth' });
}
```

Hľadaj cieľový tab cez stabilný `data-tab="osobna|online|hybrid"` atribút, nikdy cez
pozičný index — akonáhle je čo i len jeden tab vypnutý (show/hide), pozície sa
posunú a index ukáže na iný tab.

Ak je na stránke viac widgetov, ktoré si takto posielajú stav, nepoužívaj pre
všetky rovnaký názov parametra (viď "Zdieľaný stav" vyššie).

---

## Lessons learned — časté chyby

| Problém | Riešenie |
|---|---|
| CSS tokeny (`--*`) nie sú dostupné vo WP | Definovať v `register.php` cez `wp_head` action s `priority 1` |
| `.btn` trieda koliduje s Elementor globálnymi štýlmi | Vždy vlastný prefix: `.{widget-prefix}-btn` |
| Farba textu tlačidiel sa prepíše Elementorom | `color: #fff !important` na `.btn` aj `:hover` |
| Padding top/bottom ako jeden control | Vždy dva separate responsive controls |
| MEDIA control bez size control | Vždy `add_responsive_control()` pre šírku hneď za MEDIA |
| Aktívny tab hardcodovaný | Automatická detekcia cez porovnanie current URL s tab URL |
| `align-items: left` nefunguje | Správne je `align-items: flex-start` |
| Style tab chýba | Povinný pri každom widgete |
| Gradient nadpisu nemeniteľný | Riešiť cez CSS premenné + color controls |
| Subnav taby sa zalomia na mobile | `flex-shrink: 0` na taboch + `overflow-x: auto` na wraperi |
| WordPress pridáva "Súkromné:" pred title | `get_post_field('post_title', $post_id)` namiesto `get_the_title()` |
| ACF Date Picker formát nesedí | Match return format v PHP, s fallbackom na `Ymd` |
| `WP_Query` vracia 0 na private CPT | `post_status` musí obsahovať `['publish', 'private']` |
| WYSIWYG vytvára vnorené `<p><p>...</p></p>` | Renderovať do `<div>`, odstrániť prázdne `<p></p>` regexom |
| `all: unset !important` na nadpise zruší gradient text | Pridať `-webkit-text-fill-color: transparent` vo vyššej špecificite |
| Mobile drawer link s `#` v URL nenaviguje | `href.charAt(0) === '#'`, nie `href.indexOf('#')` (chybne zachytí aj externé URL s `#`) |
| `register.php` na webe bez `$dir`/`$uri` | Použiť priamo `get_stylesheet_directory_uri()` + `__DIR__` |
| Klik na CTA na inej (sub)doméne sa nedá zachytiť JS-om | URL parameter + `history.replaceState` |
| WP timezone nesedí s lokálnym časom | Nastaviť WP timezone na lokálnu zónu projektu (napr. `Europe/Bratislava`) |
| Notes/rich-text polia ako viacero samostatných controls | Jeden HTML-capable `TEXTAREA` cez `wp_kses()` |
| Panel v Style tabe sa naťahuje do nekonečna | `POPOVER_TOGGLE`, `HEADING` rozdeľovače, `start_controls_tabs` |
| Vlastná JS logika simuluje breakpointy | Nikdy — vždy `add_responsive_control()` |
| Tab-switch podľa `?tab=` otvorí iný tab keď je niektorý vypnutý | Výber cez `data-tab`/`data-key`, nikdy pozičný index |
| Dva widgety čítajú a mažú ten istý `?tab=` parameter | Widget-špecifický/účelový názov parametra |
| Časté prerábanie layoutu vedie k `widget_v1`/`v2`/`v3` a strate obsahu | Jeden widget, `SELECT` "Layout" control, nový widget len pri zmene dátového modelu |
