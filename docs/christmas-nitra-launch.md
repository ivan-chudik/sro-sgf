# Christmas Nitra 2026 — dokončenie pred spustením

## 1 · E-maily z formulára pripomienky

Elementor → Šablóny → `CN Formulár pripomienky SK` (a potom EN) → Form widget → **Actions After Submit**:
`Collect Submissions` + `Email` + `Email 2`. Najprv nastav **WP Mail SMTP** (schránka z hostingu) a pošli si testovací mail.

### Email (notifikácia pre organizátora) — rovnaký pre SK aj EN
| Pole | Hodnota |
|---|---|
| To | e-mail organizátora (napr. `christmas.nitra@gmail.com`) — overiť s klientom |
| Subject | `Nová pripomienka — Christmas Nitra 2026 ([field id="email"])` (EN formulár: pridaj „EN“) |
| Message | HTML šablóna nižšie („Message admin“), v EN formulári prepísať `SK` → `EN` |
| From Email | adresa nastavená vo WP Mail SMTP (musí sedieť, inak padá do spamu) |
| From Name | `Christmas Nitra 2026` |
| Reply-To | `[field id="email"]` |
| Send As | HTML |
| Metadáta | Dátum, Čas, URL stránky |

Message admin:
```html
<div style="background:#000;padding:32px 16px;font-family:Arial,Helvetica,sans-serif">
  <div style="max-width:560px;margin:0 auto;background:#0a0a0d;border-radius:20px;overflow:hidden;border:1px solid #2a2a33">
    <div style="height:4px;background:linear-gradient(90deg,#ff5e5d,#f65b80,#df5aa0,#ba58ab,#8e5bae,#7260b1,#5762b3)"></div>
    <div style="padding:32px 28px;color:#fff">
      <p style="margin:0 0 6px;font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:#f65b80;font-weight:bold">Christmas Nitra 2026 · sro.sgf.sk</p>
      <h1 style="margin:0 0 22px;font-size:24px;line-height:1.2;font-weight:normal;text-transform:uppercase;letter-spacing:.04em">Nová žiadosť o pripomienku</h1>
      <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:15px">
        <tr>
          <td style="padding:12px 0;border-top:1px solid #2a2a33;color:#9a9aa6;width:150px">E-mail</td>
          <td style="padding:12px 0;border-top:1px solid #2a2a33"><a href="mailto:[field id="email"]" style="color:#fff;text-decoration:none;font-weight:bold">[field id="email"]</a></td>
        </tr>
        <tr>
          <td style="padding:12px 0;border-top:1px solid #2a2a33;color:#9a9aa6">Jazyk formulára</td>
          <td style="padding:12px 0;border-top:1px solid #2a2a33;color:#fff">SK</td>
        </tr>
        <tr>
          <td style="padding:12px 0;border-top:1px solid #2a2a33;border-bottom:1px solid #2a2a33;color:#9a9aa6">Všetky polia</td>
          <td style="padding:12px 0;border-top:1px solid #2a2a33;border-bottom:1px solid #2a2a33;color:#cfcfd8">[all-fields]</td>
        </tr>
      </table>
      <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#7a7a86">Všetky prihlásené e-maily nájdeš v administrácii: Elementor → Submissions. Na tento e-mail môžeš odpovedať priamo, odpoveď pôjde návštevníkovi.</p>
    </div>
  </div>
</div>
```

### Email 2 (potvrdenie pre návštevníka)
| Pole | SK | EN |
|---|---|---|
| To | `[field id="email"]` | `[field id="email"]` |
| Subject | `Pripomienka Christmas Nitra 2026 je nastavená` | `Your Christmas Nitra 2026 reminder is set` |
| From Email / Name | ako vyššie | ako vyššie |
| Reply-To | `christmas.nitra@gmail.com` | `christmas.nitra@gmail.com` |
| Send As | HTML | HTML |

Message SK (vlož celé do poľa Message):
```html
<div style="background:#000;padding:32px 16px;font-family:Arial,Helvetica,sans-serif">
  <div style="max-width:560px;margin:0 auto;background:#0a0a0d;border-radius:20px;overflow:hidden;border:1px solid #2a2a33">
    <div style="height:4px;background:linear-gradient(90deg,#ff5e5d,#f65b80,#df5aa0,#ba58ab,#8e5bae,#7260b1,#5762b3)"></div>
    <div style="padding:32px 28px;color:#fff">
      <p style="margin:0 0 6px;font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:#f65b80;font-weight:bold">Christmas Nitra 2026</p>
      <h1 style="margin:0 0 18px;font-size:26px;line-height:1.2;font-weight:normal;text-transform:uppercase;letter-spacing:.04em">Ďakujeme, pripomienku máš nastavenú</h1>
      <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#cfcfd8">Pošleme ti najviac tri e-maily: štart predaja, program súťaže a pripomienku deň pred súťažou. Žiadny spam.</p>
      <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#cfcfd8"><b style="color:#fff">26. – 29. novembra 2026</b> · Mestská športová hala Nitra</p>
      <a href="https://tickets.sgf.sk/sk/christmas-nitra-vstupenky/" style="display:inline-block;background:#fff;color:#000;text-decoration:none;font-weight:bold;font-size:13px;letter-spacing:.1em;text-transform:uppercase;padding:14px 26px;border-radius:999px">Kúpiť vstupenku</a>
      <p style="margin:28px 0 0;font-size:12px;line-height:1.6;color:#7a7a86">Tento e-mail si dostal/-a, lebo si na sro.sgf.sk požiadal/-a o pripomienku. Ak si o ňu nežiadal/-a, stačí e-mail ignorovať. Kontakt: christmas.nitra@gmail.com</p>
    </div>
  </div>
</div>
```

Message EN:
```html
<div style="background:#000;padding:32px 16px;font-family:Arial,Helvetica,sans-serif">
  <div style="max-width:560px;margin:0 auto;background:#0a0a0d;border-radius:20px;overflow:hidden;border:1px solid #2a2a33">
    <div style="height:4px;background:linear-gradient(90deg,#ff5e5d,#f65b80,#df5aa0,#ba58ab,#8e5bae,#7260b1,#5762b3)"></div>
    <div style="padding:32px 28px;color:#fff">
      <p style="margin:0 0 6px;font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:#f65b80;font-weight:bold">Christmas Nitra 2026</p>
      <h1 style="margin:0 0 18px;font-size:26px;line-height:1.2;font-weight:normal;text-transform:uppercase;letter-spacing:.04em">Thank you, your reminder is set</h1>
      <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#cfcfd8">We'll send you three e-mails at most: sales opening, the competition schedule and a reminder the day before the event. No spam.</p>
      <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#cfcfd8"><b style="color:#fff">26 – 29 November 2026</b> · Nitra City Sports Hall, Slovakia</p>
      <a href="https://tickets.sgf.sk/christmas-nitra-tickets/" style="display:inline-block;background:#fff;color:#000;text-decoration:none;font-weight:bold;font-size:13px;letter-spacing:.1em;text-transform:uppercase;padding:14px 26px;border-radius:999px">Buy tickets</a>
      <p style="margin:28px 0 0;font-size:12px;line-height:1.6;color:#7a7a86">You received this e-mail because you asked for a reminder on sro.sgf.sk. If it wasn't you, simply ignore it. Contact: christmas.nitra@gmail.com</p>
    </div>
  </div>
</div>
```

## 2 · SEO (Yoast + Polylang)

Každá jazyková verzia stránky má **vlastný** titulok, popis a OG obrázok — nastavuje sa v Yoast boxe pod editorom danej stránky (Stránky → Domov / Home → Upraviť → Yoast SEO).

| | SK (Domov) | EN (Home) |
|---|---|---|
| SEO title | `Christmas Nitra 2026 — vstupenky a livestream · 26. – 29. 11. · Nitra` | `Christmas Nitra 2026 — tickets & livestream · 26–29 Nov · Nitra, Slovakia` |
| Meta description | `Slovak Rhythmic Gymnastics Open 26. – 29. novembra 2026 v Nitre. Svetová špička modernej gymnastiky naživo v hale alebo v livestreame. Early Bird vstupenky do 31. 10.` | `Slovak Rhythmic Gymnastics Open, 26–29 November 2026 in Nitra, Slovakia. World-class rhythmic gymnastics live in the arena or on the livestream. Early Bird tickets until 31 Oct.` |
| Focus keyphrase | `Christmas Nitra` | `Christmas Nitra` |
| Social (Facebook/X) image | `branding/og-sk.png` | `branding/og-en.png` |

**Názov a slogan webu:** Nastavenia → Všeobecné — Názov: `Christmas Nitra 2026`, Slogan: `Slovak Rhythmic Gymnastics Open`.
EN preklad: Jazyky → **Preklady** (Polylang strings) → „Site Title“ / „Tagline“ → rovnaké texty (sú anglické).

**Yoast → Nastavenia → Reprezentácia stránky:** Organizácia — `Slovenská gymnastická federácia`, logo SGF.
**Yoast → Nastavenia → Sociálne:** predvolený obrázok `og-sk.png`.
**Yoast → Nastavenia → Typy obsahu:** Články a Kategórie „Zobraziť vo výsledkoch vyhľadávania“ → Nie (web ich nepoužíva).

## 3 · Favicon
Vzhľad → Prispôsobiť → Identita webu → **Ikona webu** → nahraj `branding/favicon-512.png`.

## 4 · 404 stránka
1. Templates → Import → `elementor-templates/not-found.json` (typ 404).
2. Theme Builder → **Error 404** → otvor šablónu „Christmas Nitra — 404“ → **Publish** → podmienka sa nastaví automaticky na 404 (ak sa pýta, zvoľ „Entire site / 404“).
3. V nastaveniach šablóny (ozubené koliesko) Page Layout = **Elementor Canvas**.
4. Test: `sro.sgf.sk/neexistuje` (SK texty) a `sro.sgf.sk/en/neexistuje` (EN texty).

## 5 · Cache
- **LiteSpeed Cache** má zmysel **len ak hosting beží na LiteSpeed serveri** (overíš v administrácii hostingu alebo v hlavičke odpovede `server: LiteSpeed`). Na Apache/Nginx nerobí nič užitočné.
- Ak nie je LiteSpeed: **WP Super Cache** (zadarmo, jednoduché) — Easy → Caching On.
- WP Rocket na jednu landing page netreba.
- Po nasadení zmien v téme vždy: vymazať cache pluginu + Elementor → Nástroje → Regenerate Files & Data.
- Stránku s formulárom netreba z cache vylučovať (Elementor Form funguje cez AJAX).

## 6 · V deň spustenia
- [ ] Elementor → Nástroje → Maintenance Mode → **Disabled**
- [ ] Nastavenia → Čítanie → „Odradiť vyhľadávače“ **nezaškrtnuté**
- [ ] Vymazať cache, otestovať v inkognito okne SK aj EN
- [ ] Odoslať testovací formulár SK aj EN (príde notifikácia aj potvrdenie?)
- [ ] Yoast → prípadne odoslať sitemap (`/sitemap_index.xml`) do Google Search Console
