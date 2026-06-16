#!/bin/bash
# /usr/local/sbin/wifi-update.sh
# Gebruik: wifi-update.sh COUNTRY SSID PSK HIDDEN(0|1)
# Of:      wifi-update.sh --reconfigure
# Werkt met wpa_supplicant (Raspberry Pi OS legacy). Maakt backup en herconfigureert.

set -euo pipefail

CONF="/etc/wpa_supplicant/wpa_supplicant.conf"
IFACE="wlan0"
TS=$(date +%Y%m%d-%H%M%S)

if [[ "${1:-}" == "--reconfigure" ]]; then
  echo "[i] wpa_cli reconfigure…"
  if command -v wpa_cli >/dev/null 2>&1; then
    wpa_cli -i "$IFACE" reconfigure || true
  fi
  if systemctl is-active --quiet NetworkManager 2>/dev/null; then
    systemctl reload NetworkManager || true
  else
    systemctl restart wpa_supplicant || true
    systemctl restart dhcpcd || true
  fi
  exit 0
fi

if [[ $# -lt 4 ]]; then
  echo "Gebruik: $0 COUNTRY SSID PSK HIDDEN(0|1)"
  exit 1
fi

COUNTRY="$1"
SSID="$2"
PSK="$3"
HIDDEN="$4"

if [[ -f "$CONF" ]]; then
  cp -a "$CONF" "${CONF}.bak-${TS}"
  echo "[i] Backup gemaakt: ${CONF}.bak-${TS}"
fi

# Genereer PSK veilig via wpa_passphrase (verbergt plaintext in conf)
if ! command -v wpa_passphrase >/dev/null 2>&1; then
  echo "[!] wpa_passphrase niet gevonden. Installeer wpa_supplicant."
  exit 1
fi

TMP="$(mktemp)"
wpa_passphrase "$SSID" "$PSK" > "$TMP"

# Bouw nieuw configuratiebestand
{
  echo "country=$COUNTRY"
  echo "ctrl_interface=DIR=/var/run/wpa_supplicant GROUP=netdev"
  echo "update_config=1"
  echo
  # Neem de gegenereerde network{} over, maar zet scan_ssid indien hidden
  awk -v hidden="$HIDDEN" '
    BEGIN{ inblock=0 }
    /^network=\{/ { inblock=1; print; next }
    inblock==1 && /^\}/ {
      if (hidden=="1") print "    scan_ssid=1";
      print; inblock=0; next
    }
    { print }
  ' "$TMP"
} > "$CONF"

rm -f "$TMP"
chown root:root "$CONF"
chmod 600 "$CONF"

echo "[i] Nieuwe configuratie weggeschreven naar $CONF"
echo "[i] Herconfigureren…"

if command -v wpa_cli >/dev/null 2>&1; then
  wpa_cli -i "$IFACE" reconfigure || true
fi

if systemctl is-active --quiet NetworkManager 2>/dev/null; then
  systemctl reload NetworkManager || true
else
  systemctl restart wpa_supplicant || true
  systemctl restart dhcpcd || true
fi

echo "[✓] Klaar. Huidige SSID:"
iwgetid -r || true
ip -4 addr show "$IFACE" | awk '/inet /{print "[i] IP:", $2}' || true
