#!/bin/bash
LOG_FILE="/home/pi/kiosk-runtime.log"

log_line() {
  local message="$1"
  local line="[$(date '+%Y-%m-%d %H:%M:%S')] [refresh-once] ${message}"
  echo "$line"
  printf '%s\n' "$line" >> "$LOG_FILE" 2>/dev/null || true
}

export DISPLAY=:0
export XAUTHORITY=/home/pi/.Xauthority
if xdotool key F5; then
  log_line "Eenmalige refresh uitgevoerd."
else
  log_line "Eenmalige refresh mislukte."
fi
