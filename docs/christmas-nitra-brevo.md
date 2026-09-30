# Christmas Nitra 2026 — Brevo (formulár → zoznam → pripomienka)

Okamžité maily po odoslaní formulára (admin + potvrdenie) posiela ďalej **Elementor**, doručuje ich Brevo cez WP Mail SMTP (Mailer: Brevo).
Brevo navyše zbiera kontakty do zoznamov SK / EN a z nich sa posiela **kampaň – pripomienka** v naplánovaný deň.

## 1 · Zoznamy v Brevo
Contacts → **Lists** → Create a list (2×):
- `Christmas Nitra 2026 – pripomienka SK`
- `Christmas Nitra 2026 – reminder EN`

Pri každom zozname si poznač **ID** (číslo v stĺpci ID alebo v URL).

## 2 · wp-config.php
Nad riadok `/* That's all, stop editing! */` (API kľúč z Brevo → SMTP & API → API keys; môže byť ten istý ako vo WP Mail SMTP):
```php
define( 'XN_BREVO_API_KEY', 'xkeysib-...' );
define( 'XN_BREVO_LIST_SK', 12 );
define( 'XN_BREVO_LIST_EN', 13 );
```
Súbor wp-config.php je mimo témy a mimo repa — kľúč sa nikdy necommituje.

## 3 · Ako to funguje
`partials/brevo.php` po každom odoslaní Elementor formulára s názvom (Form Name) **„Pripomienka SK“** alebo **„Reminder EN“** pridá e-mail do príslušného zoznamu (existujúci kontakt sa len doplní do zoznamu).
- Ak Brevo neodpovie, formulár aj e-maily fungujú ďalej, kontakt ostane v Elementor → Submissions a chyba sa zapíše do `wp-content/debug.log` (ak je zapnutý WP_DEBUG_LOG).
- Kontakty prihlásené **pred** nasadením: Elementor → Submissions → Export (CSV) → Brevo → Contacts → Import → do príslušného zoznamu.

Test: odošli formulár SK aj EN → Brevo → Contacts → kontakt je v správnom zozname.

## 4 · Šablóny pripomienky
Súbory `brevo/pripomienka-sk.html` a `brevo/reminder-en.html`.

Brevo → Campaigns → **Templates** → New template → **Code your own** → vlož celý obsah súboru → Save.
- Názov šablóny: `CN 2026 – pripomienka SK` / `CN 2026 – reminder EN`
- Texty (nadpis, odstavce, riadky Kedy/Kde, tlačidlo) sa upravujú priamo v kóde — sú to bežné vety medzi značkami, netreba nič iné meniť.
- `{{ unsubscribe }}` a `{{ mirror }}` nechaj — Brevo ich nahradí odkazom na odhlásenie a zobrazením v prehliadači (odhlásenie je pri kampaniach povinné).
- Early Bird veta platí len do 31. 10. — pri neskoršom odoslaní ju zmeň alebo zmaž.

## 5 · Kampaň (keď bude známy dátum)
Campaigns → Create campaign → Email (2×, SK a EN):
| | SK | EN |
|---|---|---|
| Subject | `Christmas Nitra 2026 sa blíži` | `Christmas Nitra 2026 is coming` |
| Preview text | `26. – 29. 11. · Mestská športová hala Nitra` | `26–29 Nov · Nitra City Sports Hall` |
| From | Slovak Rhythmic Open `<noreply@sro.sgf.sk>` | rovnako |
| Reply-to | `christmas.nitra@gmail.com` | rovnako |
| Recipients | zoznam SK | zoznam EN |
| Design | šablóna SK | šablóna EN |

Pred odoslaním: **Send a test** na Gmail aj Outlook → potom **Schedule** na zvolený dátum a čas.

## 6 · DNS (sro.sgf.sk na Websupporte) — stav
- `brevo-code` TXT, `mail._domainkey.sro` (Brevo DKIM), `hcdefault._domainkey.sro` (HostCreators DKIM)
- `_dmarc.sro` — jediný záznam; po pár dňoch bez problémov zmeniť `p=none` → `p=quarantine`
- MX `sro` → mx1/mx2.hostcreators.sk (schránka noreply na príjem)
