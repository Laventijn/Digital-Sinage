# CODEX_RULES.md — Instructies voor AI-assistent (Copilot / Codex)

> **Geldig voor:** `index.php`, `netwerk.php`, alle `.sh`-scripts, `kiosk.conf`, `kiosk.service`
> **Eigenaar:** Valentijn Rombaut — Digital Signage Kiosk Project
> **Doelgroep:** AI-codeerassistent (GitHub Copilot, ChatGPT Codex, of gelijkwaardig)

---

## 1. Kernregel — Lees dit eerst

Deze codebase heeft een **vaste visuele stijl** die niet gewijzigd mag worden.
De opmaak is bewust ontworpen en wordt beheerd door de projecteigenaar.

> **Jij past uitsluitend PHP-logica, shell-scripts en configuratiebestanden aan.**
> Je raakt de look & feel niet aan zonder expliciete schriftelijke toestemming.

---

## 2. Wat je WEL mag aanpassen

| Bestand | Toegestane aanpassingen |
|---|---|
| `index.php` | PHP-logica, validatie, variabelen, commentaar, nieuwe HTML-blokken toevoegen |
| `netwerk.php` | PHP-logica, formulierverwerking, shellcommando's |
| `*.sh` scripts | Shelllogica, commando's, foutafhandeling, commentaar |
| `kiosk.conf` | Configuratiewaarden en sleutels |
| `kiosk.service` | Systemd-parameters |

### HTML in `.php`-bestanden — toegestaan
- Nieuwe `<div>`, `<section>`, `<p>`, `<input>`, `<button>` elementen **toevoegen**
- Bestaande tekst of labels aanpassen
- Bestaande inline `style="…"` attributen **verwijderen** als ze redundant zijn met de CSS
- Nieuwe elementen voorzien van **bestaande class-namen** uit `style.css`

---

## 3. Wat je NIET mag aanpassen

### ❌ Absoluut verboden — zonder uitzondering

- **`style.css`** — dit bestand mag je nooit wijzigen, niet herschrijven, niet "opkuisen"
- **`style_old.css`** — archief, aanraken verboden
- **Bestaande class-namen** in HTML verwijderen of hernoemen (bv. `panel`, `ghost`, `badge`, `actions`, `grid`, `sysgrid`, `section-title`, `notice`, `error`, `danger`, `form-row`, `helper`, `small`, `sep`)
- **Inline `style="…"` toevoegen** aan bestaande elementen
- **CSS-variabelen** (`--bg`, `--brand`, `--text`, enz.) aanpassen of verplaatsen
- De **algemene HTML-structuur** van bestaande blokken herschikken
- De **volgorde van secties** in `index.php` of `netwerk.php` wijzigen
- **JavaScript** in `<script>`-blokken aanpassen tenzij expliciet gevraagd

---

## 4. Bij twijfel — verplichte procedure

Als je ook maar de geringste twijfel hebt of een aanpassing de look & feel kan raken:

### Stap 1 — Stop en meld
Geef duidelijk aan:
```
⚠️ VISUELE IMPACT MOGELIJK
Ik wil [beschrijving aanpassing] doen in [bestandsnaam].
Dit kan mogelijk de opmaak beïnvloeden omdat [reden].
Mag ik doorgaan? (ja/nee)
```

### Stap 2 — Wacht op bevestiging
Voer de aanpassing **niet** uit voordat de gebruiker expliciet "ja" antwoordt.

### Stap 3 — Herinner aan backup
Geef altijd deze melding mee bij goedkeuring:
```
✅ Goedgekeurd. Vergeet niet eerst een GitHub-commit te maken als backup
voordat je deze wijziging doorvoert (git commit -m "backup voor visuele aanpassing").
```

---

## 5. CSS-suggesties — hoe je ze correct aanbiedt

Je **mag** suggesties doen voor nieuwe CSS-klassen of verbeteringen, maar **uitsluitend** in dit formaat:

```
💡 CSS-SUGGESTIE (niet automatisch toepassen)
Klasse: .nieuwe-klasse
Reden: [waarom dit nuttig zou zijn]
Voorgestelde code:
  .nieuwe-klasse {
    ...
  }
Actie vereist: voeg dit handmatig toe aan style.css als je dit wilt.
```

Schrijf deze suggestie **nooit** zelf weg naar `style.css`.

---

## 6. Bestandsoverzicht & status

| Bestand | Status | Opmerking |
|---|---|---|
| `style.css` | 🔒 VERGRENDELD | Nooit aanpassen |
| `style_old.css` | 🔒 ARCHIEF | Niet aanraken |
| `index.php` | ✅ Bewerkbaar | Alleen PHP + nieuwe HTML-blokken |
| `netwerk.php` | ✅ Bewerkbaar | Alleen PHP-logica |
| `install_kiosk.sh` | ✅ Bewerkbaar | Shelllogica |
| `refresh.sh` | ✅ Bewerkbaar | Shelllogica |
| `refresh_chromium.sh` | ✅ Bewerkbaar | Shelllogica |
| `wifi-update.sh` | ✅ Bewerkbaar | Shelllogica |
| `show_network_info.sh` | ✅ Bewerkbaar | Shelllogica |
| `kiosk-update.sh` | ✅ Bewerkbaar | Shelllogica |
| `kiosk.conf` | ✅ Bewerkbaar | Configuratiewaarden |
| `kiosk.service` | ✅ Bewerkbaar | Systemd-configuratie |

---

## 7. GitHub-werkwijze — verplicht bij structurele wijzigingen

Herinner de gebruiker aan de volgende stappen bij elke structurele aanpassing:

1. `git status` — controleer wat er gewijzigd is
2. `git add .` — voeg wijzigingen toe
3. `git commit -m "beschrijving van de aanpassing"` — maak een snapshot
4. `git push` — synchroniseer met GitHub

> Bij twijfel: **commit eerst, pas dan aan.**

---

## 8. Samenvatting in één zin

> Pas alleen aan **wat er achter de schermen gebeurt** (PHP, shell, config).
> Wat de gebruiker **ziet**, beslist de projecteigenaar — niet de AI.

---

*Laatste update: 2025 — Valentijn Rombaut*
