VR09032026: originele versie. Wordt overgezet naar Readme.md na verificatie
README - Installatie Digital Signage Kiosk op Raspberry Pi
=========================================================

Deze handleiding beschrijft hoe je een ZIP-bestand met kioskbestanden van een Windows-pc naar een Raspberry Pi stuurt, uitpakt en installeert.

Benodigdheden:
--------------
- Raspberry Pi 3 Model B+ met Raspberry Pi OS Desktop (Legacy, 32-bit)
- PuTTY en pscp geïnstalleerd op je Windows-pc
- ZIP-bestand genaamd Install_V2.zip met alle installatiebestanden

Stap 1: ZIP-bestand kopiëren naar Raspberry Pi
----------------------------------------------
Gebruik het volgende commando in Windows (cmd):

	pscp "C:\Script\Install_V2.zip" pi@192.168.1.42:/home/pi

Vervang het IP-adres indien nodig. Voer het wachtwoord van gebruiker 'pi' in wanneer gevraagd.

Stap 2: Inloggen op de Raspberry Pi
-----------------------------------
Gebruik PuTTY of een terminal:

	ssh pi@192.168.1.42

Stap 3: ZIP-bestand uitpakken
-----------------------------
Ga naar de juiste map en pak het bestand uit:

	cd /home/pi
	unzip Install_V2.zip -d install_kiosk

Indien unzip nog niet geïnstalleerd is:

	sudo apt install unzip

Stap 4: Installatiescript uitvoeren
-----------------------------------
Ga naar de installatiemap en voer het script uit:

	cd /home/pi/install_kiosk
	chmod +x Install_kiosk.sh
	./Install_kiosk.sh

Het script installeert alle benodigde software, zet configuratiebestanden op, en herstart de Raspberry Pi.

Stap 5: Controle na herstart
----------------------------
- Het kiosk-scherm zou zichtbaar moeten zijn.
- De configuratiepagina is bereikbaar via:
  http://<IP-van-je-Pi>/index.php
- Je kunt de instellingen aanpassen via de webinterface.

Veel succes!

