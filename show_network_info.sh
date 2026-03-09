#!/bin/bash

IP=$(hostname -I | awk '{print $1}')
GATEWAY=$(ip route | grep default | awk '{print $3}')
SSID=$(iwgetid -r)

yad --title="Netwerkinformatie" \
    --text="📡 Raspberry Pi Netwerkstatus:\n\n🔹 IP-adres: $IP\n🔹 Gateway: $GATEWAY\n🔹 WiFi SSID: $SSID" \
    --timeout=5 \
    --width=350 --height=200 \
    --window-icon="network-wireless" \
    --posx=0 --posy=0

# --timeout=5 zorgt dat het venster automatisch sluit na 5 seconden
# --posx=0 --posy=0 plaatst het venster links boven
# --window-icon voegt een icoontje toe (optioneel)