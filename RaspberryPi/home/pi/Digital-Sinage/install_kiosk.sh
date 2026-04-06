#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WEB_SOURCE_DIR="$SCRIPT_DIR"
if [ -f "$SCRIPT_DIR/../../../www/html/index.php" ]; then
  WEB_SOURCE_DIR="$SCRIPT_DIR/../../../www/html"
fi

SUDOERS_FILE="/etc/sudoers.d/kiosk-dashboard"
exec 3>/dev/tty

say() {
  echo "$@" >&3
}

total=12
current=0

progress() {
  current=$((current + 1))
  percent=$((current * 100 / total))
  bar=$(printf "%-${total}s" "#" | cut -c1-"$current")
  printf "\r[%-${total}s] %d%%" "$bar" "$percent"
}

copy_web_file() {
  local filename="$1"
  sudo cp "$WEB_SOURCE_DIR/$filename" "/var/www/html/$filename"
}

ensure_sudo_rule() {
  local rule="$1"
  if sudo grep -Fxq "$rule" "$SUDOERS_FILE" 2>/dev/null; then
    return
  fi

  echo "$rule" | sudo tee -a "$SUDOERS_FILE" >/dev/null
}

say "Installatie script Kiosk door Valentijn Rombaut (c) 2026"

say "Wil je de uitvoer enkel in logfile of ook op het scherm zien?"
say "1) Alleen logfile (/home/pi/kiosk-install.log)"
say "2) Zowel op scherm als logfile"
read -r -p "Maak een keuze (1/2): " logkeuze < /dev/tty

case "$logkeuze" in
  1) exec > /home/pi/kiosk-install.log 2>&1 ;;
  2) exec > >(tee /home/pi/kiosk-install.log) 2>&1 ;;
  *) exec > /home/pi/kiosk-install.log 2>&1 ;;
esac

say "Wil je de installatie uitvoeren?"
say "1) Ja"
say "2) Nee"
read -r -p "Maak een keuze (1/2): " installeer < /dev/tty

if [ "$installeer" = "1" ]; then
  say "Pakketlijst bijwerken..."
  sudo apt update

  say "Benodigde pakketten installeren..."
  sudo apt install -y chromium-browser xdotool apache2 php libapache2-mod-php yad python3
else
  say "Installatie van pakketten overgeslagen."
fi
progress

say "Services installeren..."
sudo cp "$SCRIPT_DIR/kiosk.service" /etc/systemd/system/kiosk.service
sudo cp "$SCRIPT_DIR/kiosk-sequence.service" /etc/systemd/system/kiosk-sequence.service
sudo systemctl daemon-reload
sudo systemctl enable kiosk.service
sudo systemctl enable kiosk-sequence.service
sudo systemctl mask xscreensaver.service || true
sudo systemctl disable apt-daily.timer apt-daily-upgrade.timer || true
sudo systemctl stop apt-daily.timer apt-daily-upgrade.timer || true
progress

say "Autostart configureren..."
{
  echo "@/home/pi/refresh.sh"
} | sudo tee /etc/xdg/lxsession/LXDE-pi/autostart >/dev/null
progress

say "Refresh scripts installeren..."
cp "$SCRIPT_DIR/refresh.sh" /home/pi/refresh.sh
cp "$SCRIPT_DIR/refresh_chromium.sh" /home/pi/refresh_chromium.sh
cp "$SCRIPT_DIR/refresh_once.sh" /home/pi/refresh_once.sh
chmod +x /home/pi/refresh.sh /home/pi/refresh_chromium.sh /home/pi/refresh_once.sh
progress

say "kiosk.conf installeren..."
sudo cp "$SCRIPT_DIR/kiosk.conf" /etc/default/kiosk.conf
sudo touch /etc/default/kiosk-presets.json
sudo chown www-data:www-data /etc/default/kiosk.conf /etc/default/kiosk-presets.json
sudo chmod 664 /etc/default/kiosk.conf /etc/default/kiosk-presets.json
progress

say "Sequence watcher installeren..."
sudo cp "$SCRIPT_DIR/kiosk_sequence_watcher.py" /usr/local/bin/kiosk-sequence-watcher.py
sudo chmod +x /usr/local/bin/kiosk-sequence-watcher.py
progress

say "Hulpscripts installeren..."
cp "$SCRIPT_DIR/show_network_info.sh" /home/pi/show_network_info.sh
chmod +x /home/pi/show_network_info.sh
sudo cp "$SCRIPT_DIR/kiosk-update.sh" /usr/local/bin/kiosk-update.sh
sudo chmod +x /usr/local/bin/kiosk-update.sh
sudo cp "$SCRIPT_DIR/wifi-update.sh" /usr/local/sbin/wifi-update.sh
sudo chmod +x /usr/local/sbin/wifi-update.sh
progress

say "Webpagina's installeren..."
sudo rm -f /var/www/html/index.html
copy_web_file "index.php"
copy_web_file "style.css"
copy_web_file "home.css"
copy_web_file "sequence.php"
copy_web_file "kiosk_runtime_helpers.php"
sudo cp "$SCRIPT_DIR/netwerk.php" /var/www/html/netwerk.php
progress

say "Cronjob toevoegen..."
existing_cron="$(crontab -l 2>/dev/null | grep -v 'refresh_chromium\.sh' || true)"
{
  printf "%s\n" "$existing_cron"
  echo "*/5 * * * * /home/pi/refresh_chromium.sh >> /home/pi/refresh_chromium_log.txt 2>&1"
} | sed '/^$/N;/^\n$/D' | crontab -
progress

say "Sudoers regels installeren..."
sudo touch "$SUDOERS_FILE"
sudo chmod 440 "$SUDOERS_FILE"
ensure_sudo_rule "www-data ALL=(root) NOPASSWD: /bin/systemctl restart kiosk.service"
ensure_sudo_rule "www-data ALL=(root) NOPASSWD: /bin/systemctl restart kiosk-sequence.service"
ensure_sudo_rule "www-data ALL=(root) NOPASSWD: /bin/systemctl reboot"
ensure_sudo_rule "www-data ALL=(root) NOPASSWD: /sbin/reboot"
ensure_sudo_rule "www-data ALL=(pi) NOPASSWD: /home/pi/refresh_once.sh"
ensure_sudo_rule "www-data ALL=(root) NOPASSWD: /usr/local/bin/kiosk-update.sh"
ensure_sudo_rule "www-data ALL=(root) NOPASSWD: /usr/local/sbin/wifi-update.sh"
progress

say "Apache en sequence watcher herstarten..."
sudo systemctl restart apache2
sudo systemctl restart kiosk-sequence.service || true
progress

say "Bestandsrechten corrigeren..."
sudo chown www-data:www-data /var/www/html/index.php /var/www/html/style.css /var/www/html/home.css /var/www/html/sequence.php /var/www/html/kiosk_runtime_helpers.php /var/www/html/netwerk.php
sudo chmod 644 /var/www/html/index.php /var/www/html/style.css /var/www/html/home.css /var/www/html/sequence.php /var/www/html/kiosk_runtime_helpers.php /var/www/html/netwerk.php
progress

say ""
say "Installatie voltooid."
read -r -p "Druk op Enter om de Raspberry Pi te herstarten..." dummy < /dev/tty
sudo reboot
