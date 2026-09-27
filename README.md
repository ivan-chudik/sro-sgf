# Elementor Custom Widget Starter

Starter repo pre Claude Code cloud sessions — obsahuje generické, site-agnostic
inštrukcie a skeleton súbory pre vývoj custom Elementor widgetov (Hello Elementor
child theme).

## Ako to použiť

1. Na GitHube: **Use this template** → vytvor nový repozitár pre konkrétny web/projekt.
2. Nainštaluj naň Claude GitHub App (alebo over že ju máš cez `/web-setup`).
3. Otvor claude.ai/code, vyber tento repo, spusti novú session so zadaním.
4. `CLAUDE.md` sa načíta automaticky. `docs/` súbory si Claude Code prečíta podľa
   potreby konkrétnej úlohy.

## Štruktúra

```
CLAUDE.md                                  ← hlavné inštrukcie (načítané vždy)
docs/
  widget-patterns.md                       ← pokročilé controls, formuláre, verzie widgetu...
  header-sticky-nav.md                     ← playbook pre header/sticky nav (čítaj pred KAŽDÝM headerom)
wp-content/themes/hello-elementor-child/
  elementor-widgets/
    register.php                           ← funkčný skeleton, doplň per projekt
    partials/button.php                    ← reusable button partial
```

## Pri novom projekte

- Ak má web klient-špecifické požiadavky (branding, špeciálne pravidlá), pridaj
  ich do `CLAUDE.md` v novom repe pod nadpis "Špecifiká tohto projektu" — nemeň
  kvôli tomu template repo.
- `register.php` doplň o skutočné widgety, ako pribúdajú (príklady sú zakomentované).
