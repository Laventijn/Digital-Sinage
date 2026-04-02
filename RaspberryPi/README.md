# Handleiding Kiosk Website

## Inleiding

Dit systeem is een digitale kiosk-oplossing op basis van een Raspberry Pi, Chromium en een webdashboard. Via de website kan je een preset kiezen, tijdelijke overrides instellen, de kioskconfiguratie bewaren en een aantal beheerfuncties gebruiken.

De kiosk is bedoeld om eenvoudig websites en Google Presentaties te tonen op een scherm. De gewone gebruiker werkt vooral via het webdashboard. De ICT-verantwoordelijke beheert daarnaast de installatie, scripts, services en updates.

## Werking in het kort

De kiosk werkt met presets.

- Een preset is een vooraf ingestelde inhoud, bijvoorbeeld een website of een Google Presentatie.
- In stap 1 kies je de hoofdpreset.
- In stap 2 kan je een of meer tijdelijke overrides plannen.
- Tijdens zo een tijdslot toont het scherm tijdelijk een andere preset.
- Buiten die tijdsloten toont het scherm opnieuw de hoofdpreset.
- In stap 3 stel je de extra instellingen in, zoals refresh, cache en aan- en uit-tijden.
- In stap 4 controleer je alles en sla je de configuratie op.

Zo blijft het systeem eenvoudig: een vaste hoofdpreset met optionele tijdelijke afwijkingen op bepaalde uren.

---

## Deel 1 - Handleiding voor de gebruiker

## 1. Inleiding

De gebruiker werkt via de website van het kioskdashboard. Daar kies je wat op het scherm moet verschijnen en wanneer.

Je hoeft daarvoor normaal geen Linux-kennis te hebben. Alles gebeurt via de browser en de knoppen op de website.

## 2. IP opzoeken om de website te openen

Om de website te openen, heb je het IP-adres van de Raspberry Pi nodig.

### Methode op het scherm

1. Sluit een muis aan op het bakje of de Raspberry Pi achter het scherm.
2. Beweeg de muis zodat je toegang hebt tot het scherm.
3. Klik rechtsboven op het tandwieltje.
4. Daar zie je de netwerk- en beheerinformatie.
5. Noteer het IP-adres van de Raspberry Pi.
6. Open op een andere computer een browser en ga naar:

`http://IP-ADRES`

Voorbeeld:

`http://192.168.1.45`

## 3. De website gebruiken

De website werkt in vier stappen.

### Stap 1 - Hoofdpreset kiezen

In stap 1 kies je de hoofdpreset.

- Dit is de inhoud die standaard op het scherm blijft staan.
- Dat kan een website zijn of een Google Presentatie.
- Je kan ook presets toevoegen, wijzigen of verwijderen.

### Preset toevoegen

Gebruik het plus-icoon.

Vul daarna in:

- Naam
- Omschrijving
- Soort: website of Google Presentatie
- URL

Klik daarna op `Opslaan`.

### Preset wijzigen

Gebruik het potlood-icoon.

Pas de gegevens aan en klik opnieuw op `Opslaan`.

### Preset verwijderen

Gebruik het vuilbak-icoon.

Het systeem vraagt extra bevestiging zodat je niet per ongeluk een preset verwijdert.

## 4. Sequence overrides instellen

In stap 2 stel je tijdelijke overrides in.

Dit werkt als volgt:

- Je kiest eerst in stap 1 een hoofdpreset.
- Daarna ga je naar stap 2.
- Daar kies je een andere preset als override.
- Je geeft een starttijd en stoptijd op.
- Tijdens dat tijdslot toont de kiosk tijdelijk die andere preset.
- Daarna keert het scherm automatisch terug naar de hoofdpreset.

Voorbeeld:

- Hoofdpreset: Google
- Override: Okul Sunumu
- Tijdslot: 12:19 tot 12:23

Dan gebeurt dit:

- Om 12:18 zie je Google
- Om 12:21 zie je Okul Sunumu
- Om 12:24 zie je opnieuw Google

Als je geen overrides toevoegt, blijft de hoofdpreset altijd actief.

## 5. Instellingen voor de gebruiker

In stap 3 kan je de algemene instellingen aanpassen.

### Automatisch verversen

Hier stel je in om de hoeveel seconden de kiosk zichzelf ververst.

Gebruik dit vooral voor websites die vaak wijzigen.

### Cache legen

Hier bepaal je om de hoeveel uur de cache leeggemaakt wordt.

Dat helpt wanneer een website of presentatie niet snel genoeg vernieuwt.

### Pi aan / Pi uit

Hier kan je begin- en einduren instellen.

Zo kan het systeem gebruikt worden binnen vaste schooluren of openingsuren.

### Google Presentatie instellingen

Voor presentaties kan je ook instellen:

- automatische start
- duur per slide

## 6. Controle en opslaan

In stap 4 zie je een overzicht van:

- gekozen preset
- type inhoud
- URL
- refresh
- cache
- aan- en uit-tijden
- overrides

Controleer alles goed en klik op:

`Opslaan en toepassen`

Daarna wordt de kioskconfiguratie bewaard en opnieuw toegepast.

## 7. Gevorderde gebruiker instellingen

Via het tandwieltje rechtsboven open je het Beheer Dashboard.

Daar kan je onder andere:

- de status van kiosk, refresh service en SSH bekijken
- het IP-adres controleren
- de kiosk herstarten
- de cache leegmaken
- SSH starten of stoppen

Dit deel is bedoeld voor gevorderde gebruikers of begeleiders die net iets meer controle nodig hebben.

Let op:

- Verander alleen instellingen die je begrijpt.
- Gebruik SSH alleen als dat nodig is.
- Cache legen is nuttig als een website oud blijft laden.

## 8. Uitleg over de website en het configuratiescherm

De website bestaat uit twee delen.

### A. Het hoofdscherm

Dit is het gewone dashboard waar je:

- presets kiest
- overrides plant
- instellingen invult
- de configuratie opslaat

### B. Het configuratiescherm / beheer dashboard

Dit open je via het tandwieltje.

Hier zie je:

- service-statussen
- netwerkgegevens
- technische beheerknoppen

Dit scherm is vooral handig voor controle, probleemoplossing en snelle beheeracties.

## 9. Updates opladen

Voor een gewone gebruiker betekent een update meestal:

- een nieuwe preset toevoegen
- een bestaande preset wijzigen
- een nieuwe URL ingeven
- een andere Google Presentatie instellen

Dat gebeurt rechtstreeks via de website.

Voor software-updates van het systeem zelf is meestal de ICT-verantwoordelijke nodig.

---

## Deel 2 - Handleiding voor ICT

## 1. Overzicht van de opbouw

De oplossing bestaat uit vier lagen:

1. De Raspberry Pi
2. De webinterface in PHP
3. Chromium in kioskmodus
4. Achterliggende scripts en services

Belangrijke onderdelen:

- Webdashboard: `/var/www/html/index.php`
- Hulpfuncties: `/var/www/html/kiosk_runtime_helpers.php`
- Debug/preview: `/var/www/html/sequence.php`
- Configuratie: `/etc/default/kiosk.conf`
- Presets: `/etc/default/kiosk-presets.json`

## 2. Belangrijkste services

### kiosk.service

Deze service start Chromium in kioskmodus met de URL uit `kiosk.conf`.

Belangrijk:

- draait als gebruiker `pi`
- leest `KioskURL` uit `/etc/default/kiosk.conf`
- toont na opstart netwerkinfo via `show_network_info.sh`

### kiosk-sequence.service

Deze service start de Python watcher die controleert of een geplande override actief moet zijn.

### Apache

Apache serveert het dashboard via `/var/www/html`.

## 3. Belangrijkste scripts

### install_kiosk.sh

Doel:

- installeert pakketten
- zet services klaar
- kopieert webbestanden
- installeert refresh- en hulpscripts
- maakt `kiosk.conf` en `kiosk-presets.json` aan
- activeert de sequence watcher

Gebruik:

`/home/pi/Digital-Sinage/install_kiosk.sh`

### refresh.sh

Loopt permanent en drukt periodiek op `F5` op basis van `RefreshTime` in `kiosk.conf`.

### refresh_once.sh

Voert een eenmalige `F5` uit.

Handig voor een snelle manuele refresh vanuit het dashboard.

### kiosk_sequence_watcher.py

Controleert de geplande overrides.

Werking:

- leest de hoofdpreset
- leest de override-tijdsloten
- bepaalt of een override actief is
- schrijft indien nodig een nieuwe runtime-URL naar `kiosk.conf`
- triggert daarna refresh of kiosk herstart

### kiosk-update.sh

Schrijft rechtstreeks een kioskconfiguratie weg en herstart `kiosk.service`.

Dit script is bruikbaar voor CLI-beheer of geautomatiseerde updates.

### wifi-update.sh

Schrijft Wi-Fi instellingen naar `wpa_supplicant` en herconfigureert de verbinding.

Opmerking:

Het Wi-Fi blok in de website is voorbereid voor beheer, maar voor volwaardig netwerkbeheer blijft deze scriptlaag belangrijk.

## 4. Configuratiebestanden

### /etc/default/kiosk.conf

Bevat onder andere:

- `KioskURL`
- `KioskMode`
- `SelectedPresetUrl`
- `ResolvedPresetUrl`
- `SequenceData`
- `RefreshTime`
- `CacheInterval`
- `StartTime`
- `StopTime`

### /etc/default/kiosk-presets.json

Bevat alle opgeslagen presets.

Elke preset bevat minstens:

- naam
- omschrijving
- type
- URL

## 5. Deployment en updates

### Webbestanden vernieuwen

De voornaamste projectbestanden staan in:

- `/home/pi/Digital-Sinage/`
- `/var/www/html/`

Bij een update moeten minstens deze bestanden correct staan:

- `index.php`
- `kiosk_runtime_helpers.php`
- `sequence.php`
- `style.css`
- `home.css`

### Aanbevolen werkwijze

1. Maak een backup van `kiosk.conf` en `kiosk-presets.json`.
2. Kopieer de nieuwe webbestanden naar `/var/www/html/`.
3. Controleer rechten en eigenaars.
4. Herstart Apache indien nodig.
5. Herstart `kiosk.service`.
6. Herstart `kiosk-sequence.service`.
7. Test het dashboard en een geplande override.

## 6. Rechten en eigenaars

Volgens de installatie worden onder andere deze rechten ingesteld:

- `www-data` schrijft naar `kiosk.conf`
- `www-data` schrijft naar `kiosk-presets.json`
- webbestanden in `/var/www/html/` zijn leesbaar voor Apache

Daarnaast worden sudoers-regels toegevoegd voor onder andere:

- herstart van `kiosk.service`
- herstart van `kiosk-sequence.service`
- reboot
- refresh once
- kiosk-update
- wifi-update

Controleer deze regels altijd zorgvuldig bij wijzigingen.

## 7. Standaard beheeracties

### Kiosk opnieuw starten

Via dashboard:

- open tandwieltje
- kies `Kiosk herstarten`

Via terminal:

`sudo systemctl restart kiosk.service`

### Sequence watcher opnieuw starten

`sudo systemctl restart kiosk-sequence.service`

### Apache opnieuw starten

`sudo systemctl restart apache2`

### SSH starten of stoppen

Kan via het beheer dashboard of via systemd:

- `sudo systemctl start ssh`
- `sudo systemctl stop ssh`

## 8. Probleemoplossing

### Het dashboard opent niet

Controleer:

- IP-adres
- netwerkverbinding
- Apache status

Commando:

`sudo systemctl status apache2`

### De kiosk toont oude inhoud

Mogelijke oorzaken:

- cache nog niet ververst
- refresh te traag ingesteld
- website blokkeert caching niet goed

Aanpak:

- cache legen via dashboard
- een eenmalige refresh uitvoeren
- kiosk herstarten

### Een override wordt niet actief

Controleer:

- of de hoofdpreset correct gekozen is
- of de override een andere preset gebruikt
- of het tijdslot juist is ingegeven
- of de timezone correct staat
- of `kiosk-sequence.service` actief is

Commando:

`sudo systemctl status kiosk-sequence.service`

### Geen IP zichtbaar

Controleer:

- netwerkverbinding
- Wi-Fi configuratie
- `show_network_info.sh`

## 9. Aanbeveling voor beheer

Voor een stabiele werking is dit aanbevolen:

- hou een backup van `kiosk.conf`
- hou een backup van `kiosk-presets.json`
- test nieuwe presets eerst handmatig
- gebruik duidelijke presetnamen
- beperk technische toegang tot ICT of gevorderde gebruikers
- documenteer elke software-update kort

---

## Slot

Deze kioskwebsite is opgebouwd om eenvoudig te gebruiken te zijn voor gewone gebruikers, maar tegelijk voldoende controle te geven aan ICT. De combinatie van presets, tijdelijke overrides, webbeheer en scripts maakt het systeem flexibel voor scholen, presentaties en infoschermen.

Als gewenst kan deze handleiding later nog omgezet worden naar:

- een Word-document
- een printbare PDF
- een verkorte gebruikerskaart van 1 pagina
