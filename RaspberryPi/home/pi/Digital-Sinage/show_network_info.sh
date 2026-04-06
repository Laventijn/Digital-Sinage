#!/bin/bash

LOG_FILE="/home/pi/kiosk-runtime.log"

log_line() {
    local message="$1"
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] [network-info] ${message}"
    printf '%s\n' "$line" >> "$LOG_FILE" 2>/dev/null || true
}

IP=$(hostname -I | awk '{print $1}')
GATEWAY=$(ip route | grep default | awk '{print $3}')
SSID=$(iwgetid -r)

log_line "Netwerkinformatie weergegeven. ip=${IP:-none} gateway=${GATEWAY:-none} ssid=${SSID:-none}"

yad --title="Netwerkinformatie" \
    --text="📡 Raspberry Pi Netwerkstatus:\n\n🔹 IP-adres: $IP\n🔹 Gateway: $GATEWAY\n🔹 WiFi SSID: $SSID" \
    --timeout=5 \
    --width=350 --height=200 \
    --window-icon="network-wireless" \
    --posx=0 --posy=0

# --timeout=5 zorgt dat het venster automatisch sluit na 5 seconden
# --posx=0 --posy=0 plaatst het venster links boven
# --window-icon voegt een icoontje toe (optioneel)
