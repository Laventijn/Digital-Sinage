#!/bin/bash

set -euo pipefail

KIOSK_CONF="/etc/default/kiosk.conf"

KIOSK_URL="${1:-http://localhost/}"
REFRESH_TIME="${2:-30}"
CACHE_INTERVAL="${3:-2}"
START_TIME="${4:-}"
STOP_TIME="${5:-}"
KIOSK_MODE="${6:-website}"
SELECTED_PRESET_URL="${7:-}"
SEQUENCE_KEY="${8:-}"
RESOLVED_PRESET_URL="${9:-$KIOSK_URL}"
TIMEZONE="${10:-Europe/Brussels}"
SLIDE_START="${11:-true}"
SLIDE_LOOP="${12:-true}"
SLIDE_DELAY="${13:-5000}"

cat > "$KIOSK_CONF" <<EOF
# ==========================================
# Kiosk configuratiebestand
# ==========================================

# Runtime doel
KioskURL=${KIOSK_URL}
KioskMode=${KIOSK_MODE}
SelectedPresetUrl=${SELECTED_PRESET_URL}
SequenceKey=${SEQUENCE_KEY}
ResolvedPresetUrl=${RESOLVED_PRESET_URL}
Timezone=${TIMEZONE}

# Google Slides opties
SlideStart=${SLIDE_START}
SlideLoop=${SLIDE_LOOP}
SlideDelay=${SLIDE_DELAY}

# Refresh instellingen
RefreshTime=${REFRESH_TIME}
CacheInterval=${CACHE_INTERVAL}

# Tijdschema (optioneel)
EOF

if [ -n "$START_TIME" ]; then
  echo "StartTime=${START_TIME}" >> "$KIOSK_CONF"
else
  echo "#StartTime=08:00" >> "$KIOSK_CONF"
fi

if [ -n "$STOP_TIME" ]; then
  echo "StopTime=${STOP_TIME}" >> "$KIOSK_CONF"
else
  echo "#StopTime=18:00" >> "$KIOSK_CONF"
fi

systemctl restart kiosk.service
