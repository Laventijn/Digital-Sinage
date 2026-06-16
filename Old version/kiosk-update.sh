#!/bin/bash
KIOSK_CONF="/etc/default/kiosk.conf"

echo "KioskURL=$1" > "$KIOSK_CONF"
echo "RefreshTime=$2" >> "$KIOSK_CONF"
echo "CacheInterval=$3" >> "$KIOSK_CONF"

if [ -n "$4" ]; then
  echo "StartTime=$4" >> "$KIOSK_CONF"
fi

if [ -n "$5" ]; then
  echo "StopTime=$5" >> "$KIOSK_CONF"
fi

systemctl restart kiosk.service
