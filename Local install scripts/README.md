# Local install scripts

Deze map bevat helper-scripts om het project lokaal te openen en te testen in Visual Studio Code op Windows.

Bestanden:

- `open-website-in-vscode.bat`: Helper om snel de website-map in VS Code te openen.
- `github-push.ps1`: PowerShell script om lokale wijzigingen te committen en naar GitHub te pushen.
- `setup-pi-ssh.ps1`: PowerShell helper voor het opzetten van SSH naar de Raspberry Pi (gebruik voor Pi-specifieke taken).
- `setup-vscode-windows.bat`: (Nieuw) Interactieve batch die:
  - Controleert of Git, Visual Studio Code en PHP aanwezig zijn
  - Vraagt of je het project in VS Code wilt openen
  - Optioneel een lokale PHP-server start op http://localhost:8000 in een nieuw venster
  - Geeft links naar de benodigde downloadpagina's als iets ontbreekt

Gebruik:

1. Open een PowerShell of Command Prompt met voldoende rechten.
2. Ga naar de projectmap en navigeer naar `Local install scripts`.
3. Start het script met dubbelklik of via de commandline:

```bat
Local install scripts\setup-vscode-windows.bat
```

Opmerkingen:

- Het script voert geen software-installatie uit; het geeft instructies en opent tools indien aanwezig.
- Voor uitgebreide Pi-setup, gebruik de scripts in `RaspberryPi/`.

Voeg gerust extra checks of functies toe als je wilt dat het ook automatisch tools installeert.
