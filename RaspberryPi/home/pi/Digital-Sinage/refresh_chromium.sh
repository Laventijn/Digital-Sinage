#!/bin/bash
LOG_FILE="/home/pi/kiosk-runtime.log"

log_line() {
  local message="$1"
  local line="[$(date '+%Y-%m-%d %H:%M:%S')] [cache-clear] ${message}"
  echo "$line" >> /home/pi/refresh_chromium_log.txt
  printf '%s\n' "$line" >> "$LOG_FILE" 2>/dev/null || true
}

echo "==== Voor opkuis cache: $(date) ====" >> /home/pi/refresh_chromium_log.txt
df -h >> /home/pi/refresh_chromium_log.txt
log_line "Chromium cache opkuis gestart."

sudo systemctl stop kiosk.service
rm -rf /home/pi/.cache/chromium/
sudo systemctl start kiosk.service

echo "==== Na opkuis cache: $(date) ====" >> /home/pi/refresh_chromium_log.txt
df -h >> /home/pi/refresh_chromium_log.txt
log_line "Chromium cache opkuis voltooid."
