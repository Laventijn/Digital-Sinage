# open-website-in-vscode.bat — Gebruik

Dit batchbestand opent de webmap in VS Code, start (optioneel) de ingebouwde PHP-server en opent de browser.

Standaardinstellingen
- Webmap: `RaspberryPi/var/www/html` (pas `SITE_DIR` aan in het .bat als anders)
- Poort: `8000` (standaard)

Voorbeelden

1) Standaard (poort 8000):

```powershell
cmd /c .\open-website-in-vscode.bat
```

2) Specifieke poort (bijv. 8080):

```powershell
cmd /c .\open-website-in-vscode.bat 8080
```

3) Specifiek php-pad:

```powershell
cmd /c .\open-website-in-vscode.bat 8000 "C:\php\php.exe"
```

4) Open VS Code via Remote SSH (vereist `code` in PATH):

```powershell
cmd /c .\open-website-in-vscode.bat 8000 "" --remote ssh-remote+raspberrypi-kiosk
```

Opmerkingen / Aandachtspunten
- Het script zoekt automatisch naar `php` via `where php` en in veelvoorkomende paden. Als het geen `php` vindt, start het alleen VS Code en toont een waarschuwing.
- Het start `code` alleen als de `code` CLI beschikbaar is. Voeg `code` toe aan PATH vanuit VS Code als dat nog ontbreekt.
- De ingebouwde PHP-server is uitsluitend bedoeld voor lokale previews. De volledige kiosk-functies (systemctl, kiosk.service, Apache) werken alleen op een Linux/Raspberry Pi-omgeving.

Als je wilt kan ik het script nog uitbreiden om automatisch WSL te gebruiken, of een PowerShell-variant maken die iets vriendelijker omgaat met aanhalingstekens en gebruikerspaden.