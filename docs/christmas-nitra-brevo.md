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
define( 'XN_BREVO_LIST_SK', 116 );
define( 'XN_BREVO_LIST_EN', 117 );
```
Súbor wp-config.php je mimo témy a mimo repa — kľúč sa nikdy necommituje.

## 3 · Ako to funguje
`partials/brevo.php` po každom odoslaní Elementor formulára s názvom (Form Name) **„Pripomienka SK“** alebo **„Reminder EN“** pridá e-mail do príslušného zoznamu (existujúci kontakt sa len doplní do zoznamu).
- Ak Brevo neodpovie, formulár aj e-maily fungujú ďalej, kontakt ostane v Elementor → Submissions a chyba sa zapíše do `wp-content/debug.log` (ak je zapnutý WP_DEBUG_LOG).
- Kontakty prihlásené **pred** nasadením: Elementor → Submissions → Export (CSV) → Brevo → Contacts → Import → do príslušného zoznamu.

Test: odošli formulár SK aj EN → Brevo → Contacts → kontakt je v správnom zozname.

## 4 · Šablóny pripomienky
Súbory `brevo/pripomienka-sk.html` a `brevo/reminder-en.html` (vo VS Code po `git pull` → Ctrl+A → kopírovať).

Brevo → Marketing → **Templates** → New template:
1. Názov (ceruzka pri „New template“): `CN 2026 – pripomienka SK` / `CN 2026 – reminder EN`.
2. Pravý panel:
   | | SK | EN |
   |---|---|---|
   | Sender email | `noreply@sro.sgf.sk` | rovnako |
   | Sender name | `Slovak Rhythmic Open` | rovnako |
   | Subject line | `Christmas Nitra 2026 sa blíži` | `Christmas Nitra 2026 is coming` |
   | Preview text | `26. – 29. 11. · Mestská športová hala Nitra` | `26–29 Nov · Nitra City Sports Hall` |
   | Advanced settings → Reply-to | `christmas.nitra@gmail.com` | rovnako |
3. **Add content** → možnosť s kódom (Code your own / Paste your code / HTML) → vlož celý obsah súboru → **Save**.
4. **Preview & test** → test na Gmail aj Outlook (logo, tlačidlá, odkazy).

Poznámky:
- Stav „Inactive“ nevadí — aktivácia je len pre automatizácie, pri kampani sa šablóna vyberá ručne.
- Texty (nadpis, odstavce, riadky Kedy/Kde, tlačidlo) sa upravujú priamo v kóde — bežné vety medzi značkami, nič iné netreba meniť.
- `{{ unsubscribe }}` a `{{ mirror }}` nechaj — Brevo ich nahradí odkazom na odhlásenie a zobrazením v prehliadači (odhlásenie je pri kampaniach povinné).
- Early Bird veta platí len do 31. 10. — pri neskoršom odoslaní ju zmeň alebo zmaž.
- Logo sa načítava z `https://sro.sgf.sk/wp-content/themes/...` (verejné aj pri Maintenance mode). Pre nezávislosť od témy ho možno nahrať do Brevo a URL v šablóne nahradiť.

## 5 · Odhlasovacia stránka len pre tento projekt
Účet AltTag Media je **zdieľaný** viacerými klientmi a predvolené odhlásenie v Brevo platí **pre celý účet** — kto sa odhlási z pripomienky, prestal by dostávať aj kampane iných projektov. Preto vlastná odhlasovacia stránka, ktorá odoberie kontakt len zo zoznamu tohto projektu.

Brevo → Marketing → **Forms** → Create a form (2×, SK a EN):
1. **Setup:** Form name `CN 2026 – odhlásenie SK` / `CN 2026 – unsubscribe EN` → Next. Ak sa pýta na typ, zvoliť **Unsubscribe / Unsubscription form** (nie Subscription).
2. **Design:** nadpis „Odhlásenie z pripomienok Christmas Nitra 2026“ / „Unsubscribe from Christmas Nitra 2026 reminders“, tlačidlo „Odhlásiť“ / „Unsubscribe“. E-mail Brevo predvyplní.
3. **Settings:** odhlásiť **len zo zoznamu #116** (SK) / **#117** (EN) — **nie** „unsubscribe from all / blacklist“.
4. **Messages:** „Hotovo, pripomienky Christmas Nitra ti už nepošleme.“ / „Done — you won't receive Christmas Nitra reminders anymore.“
5. **Share:** nič, len uložiť.

V kampani: **Advanced settings → Unsubscribe page / Custom unsubscribe page** → vybrať príslušný formulár. Šablóna sa nemení, `{{ unsubscribe }}` povedie na túto stránku.

Ak sprievodca Forms ponúka len prihlasovací formulár, nastavenie odhlasovacej stránky treba hľadať priamo v nastaveniach kampane.

Dobré vedieť:
- Odkaz na odhlásenie v **testovacom** maile (Preview & test / Send a test) je len ukážka — zobrazí „úspešne odhlásený“, ale nič nezmení (na karte kontaktu ostáva „Subscribed“).
- Pri skutočnej kampani odhlásený kontakt v zozname **ostáva**, Brevo ho len pri odosielaní preskočí.
- Brevo má jeden kontakt na e-mail pre celý účet — atribúty (napr. KRAJINA, REGTYPE) môžu pochádzať z iných projektov. Príjemcov kampane vyberať **podľa zoznamu**, nie podľa atribútov, a atribúty cudzích kontaktov nemeniť.

## 6 · Kampaň (keď bude známy dátum)
Campaigns → Create campaign → Email (2×, SK a EN):
| | SK | EN |
|---|---|---|
| Subject | `Christmas Nitra 2026 sa blíži` | `Christmas Nitra 2026 is coming` |
| Preview text | `26. – 29. 11. · Mestská športová hala Nitra` | `26–29 Nov · Nitra City Sports Hall` |
| From | Slovak Rhythmic Open `<noreply@sro.sgf.sk>` | rovnako |
| Reply-to | `christmas.nitra@gmail.com` | rovnako |
| Recipients | zoznam #116 | zoznam #117 |
| Design | šablóna SK | šablóna EN |
| Advanced → Unsubscribe page | `CN 2026 – odhlásenie SK` | `CN 2026 – unsubscribe EN` |

Pred odoslaním: **Send a test** na Gmail aj Outlook → potom **Schedule** na zvolený dátum a čas.

## 7 · DNS (sro.sgf.sk na Websupporte) — stav
- `brevo-code` TXT, `mail._domainkey.sro` (Brevo DKIM), `hcdefault._domainkey.sro` (HostCreators DKIM)
- `_dmarc.sro` — jediný záznam; po pár dňoch bez problémov zmeniť `p=none` → `p=quarantine`
- MX `sro` → mx1/mx2.hostcreators.sk (schránka noreply na príjem)
