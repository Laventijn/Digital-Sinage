# VS Code + Raspberry Pi setup (SSH en GitHub backup)

Deze setup is gemaakt voor deze repo zodat je snel en efficient kan werken op je Raspberry Pi.

## 1. Eerste keer: SSH klaarzetten

Open een terminal in deze map en run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\setup-pi-ssh.ps1 -HostName 192.168.1.42
```

Als je terminal in de map erboven staat (`VS_PI`), gebruik dan:

```powershell
powershell -ExecutionPolicy Bypass -File .\Digital-Sinage\scripts\setup-pi-ssh.ps1 -HostName 192.168.1.42
```

Pas het IP adres aan naar je echte Raspberry Pi IP.

Wat dit script doet:
- maakt een SSH sleutel als die nog niet bestaat
- zet een host alias in je SSH config: `raspberrypi-kiosk`
- kopieert je publieke sleutel naar de Pi
- test login zonder paswoord

## 2. In VS Code verbinden met je Pi

Gebruik task:
- `SSH: Open Pi in VS Code`

Of command line:

```powershell
code --remote ssh-remote+raspberrypi-kiosk /home/pi/Digital-Sinage
```

## 3. Dagelijkse GitHub backup workflow

Gebruik task:
- `GitHub: Backup commit + push`

Dit voert uit:
- `git add -A`
- `git commit -m "backup: datum tijd"`
- `git push origin HEAD`

Handmatig kan ook:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\backup-github.ps1 -Message "update kiosk styling"
```

## 4. Snelle checks

- SSH test: task `SSH: Test Raspberry Pi`
- Git status: task `Git: Status`

## 5. Aanrader op de Raspberry Pi

Voer eenmalig uit op de Pi:

```bash
sudo apt update
sudo apt install -y git openssh-server
sudo systemctl enable ssh
sudo systemctl start ssh
```

## 6. Tips voor stabiel werken

- Gebruik altijd de alias `raspberrypi-kiosk` i.p.v. losse IP commands
- Maak kleine commits met duidelijke message
- Doe op het einde van elke werkdag 1 backup push
- Gebruik branches voor grote wijzigingen
