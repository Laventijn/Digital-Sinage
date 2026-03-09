#!/bin/bash

#-------------------
#Ga naar de installatiemap en voer het script uit:
# in CMD: pscp "C:\Script\Install_V3.zip" pi@192.168.1.42:/home/pi/install_kiosk/
#	cd /home/pi/install_kiosk
#	chmod +x install_kiosk.sh
#	./install_kiosk.sh
#------------------

# Open /dev/tty één keer
exec 3>/dev/tty

# Functie voor output naar scherm
say() {
    echo "$@" >&3
}

say "Installatie script Kiosk door Valentijn Rombaut (c) 2025"

#!/bin/bash

exec 3>/dev/tty

say() {
    echo "$@" >&3
}

# --- Voorbereiden voortgang ---
total=10       # aantal hoofdtaken
current=0

progress() {
    current=$((current+1))
    percent=$((current * 100 / total))
    bar=$(printf "%-${total}s" "#" | cut -c1-$current)
    printf "\r[%-${total}s] %d%%" "$bar" "$percent"
}

say "Installatie script Kiosk door Valentijn Rombaut (c) 2025"

# --- Vraag hoe logging moet gebeuren ---
say "Wil je de uitvoer enkel in logfile of ook op het scherm zien?"
say "1) Alleen logfile (/home/pi/kiosk-install.log)"
say "2) Zowel op scherm als logfile"
read -p "Maak een keuze (1/2): " logkeuze < /dev/tty

case $logkeuze in
  1)
    exec > /home/pi/kiosk-install.log 2>&1
    say "Logging gestart (alleen logfile)..."
    ;;
  2)
    exec > >(tee /home/pi/kiosk-install.log) 2>&1
    say "Logging gestart (scherm + logfile)..."
    ;;
  *)
    say "Ongeldige keuze, standaard: alleen logfile."
    exec > /home/pi/kiosk-install.log 2>&1
    ;;
esac

# --- Vraag of installatie moet doorgaan ---
say "Wil je de installatie uitvoeren?"
say "1) Ja, uitvoeren"
say "2) Nee, overslaan"
read -p "Maak een keuze (1/2): " installeer < /dev/tty

if [ "$installeer" = "1" ]; then
    
    
    # Update en installatie
    say"--------------------------------------------------------------------"
    say "Update pakketlijst..."
    sudo apt update
    say "Installeren: chromium-browser..."
    sudo apt install -y chromium-browser
    say "Installeren: xdotool..."
    sudo apt install -y xdotool
    say "Installeren: apache2..."
    sudo apt install -y apache2
    say "Installeren: php..."
    sudo apt install -y php
    say "Installeren: libapache2-mod-php..."
    sudo apt install -y libapache2-mod-php
    say "Installeren: yad..."
    sudo apt install -y yad
    say "--------------------------------------------------------------------"
    progress

    else
      say "Installatie overgeslagen."
fi



say "De files worden verder gekopiëerd....."



# Kiosk service installeren
say "Kiosk service installeren..."
sudo cp kiosk.service /etc/systemd/system/kiosk.service
sudo systemctl enable kiosk.service
sudo systemctl mask xscreensaver.service
sudo systemctl disable apt-daily.timer apt-daily-upgrade.timer
sudo systemctl stop apt-daily.timer apt-daily-upgrade.timer
progress

# Autostart configureren
say "Autostart configureren..."

{
  echo "@/home/pi/refresh.sh"
  echo "@/home/pi/show_network_info.sh"
} | sudo tee /etc/xdg/lxsession/LXDE-pi/autostart >/dev/null
progress

# Refresh scripts installeren
say "Refresh scripts installeren..."
cp refresh.sh /home/pi/refresh.sh
chmod +x /home/pi/refresh.sh
cp refresh_chromium.sh /home/pi/refresh_chromium.sh
chmod +x /home/pi/refresh_chromium.sh
progress

#kiosk.conf
say "Kiosk.conf installeren..."
sudo cp kiosk.conf /etc/default/kiosk.conf
sudo chmod +x /etc/default/kiosk.conf

# show_network_info
say "show_network_info installeren..."
cp show_network_info.sh /home/pi/show_network_info.sh
chmod +x /home/pi/show_network_info.sh
progress

# Webpagina netwerk instellingen
say "Webpagina netwerk instellingen..."
sudo cp netwerk.php /var/www/html/netwerk.php
sudo cp style.css /var/www/html/style.css
sudo cp wifi-update.sh /usr/local/sbin/wifi-update.sh
sudo chmod +x /usr/local/sbin/wifi-update.sh
progress

# Cronjob toevoegen
say "Cronjob toevoegen..."
(crontab -l 2>/dev/null; echo "0 */2 * * * /home/pi/refresh_chromium.sh >> /home/pi/refresh_chromium_log.txt") | crontab -
progress

# Webpagina installeren
say "Webpagina installeren..."
sudo rm -f /var/www/html/index.html
sudo cp index.php /var/www/html/index.php
progress

# Sudoers regels toevoegen voor www-data
say "Sudoers regels toevoegen voor www-data..."
{
  echo "pi ALL=NOPASSWD: /bin/systemctl restart kiosk.service"
  echo "www-data ALL=(ALL) NOPASSWD: /usr/local/bin/kiosk-update.sh"
  echo "www-data ALL=(ALL) NOPASSWD: /usr/local/sbin/wifi-update.sh"
  echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart kiosk"
  echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl reboot"
  echo "www-data ALL=(ALL) NOPASSWD: /sbin/reboot"
  echo "www-data ALL=(ALL) NOPASSWD: /home/pi/refresh.sh"
  echo "www-data ALL=(ALL) NOPASSWD: /etc/default/kiosk.conf"
} | sudo tee -a /etc/sudoers >/dev/null
progress

# Helper script kiosk update installeren
say "Helper script installeren..."
sudo cp kiosk-update.sh /usr/local/bin/kiosk-update.sh
sudo chmod +x /usr/local/bin/kiosk-update.sh
progress

# Eindmelding
say "Klaar!"
say "Druk op Enter om te herstarten..."

# Wachten op Enter
read -p "Druk op Enter om te herstarten..." dummy < /dev/tty

# Herstart systeem
sudo reboot
