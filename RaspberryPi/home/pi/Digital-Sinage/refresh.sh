#!/bin/bash

CONFIG_FILE="/etc/default/kiosk.conf"
LOG_FILE="/home/pi/kiosk-runtime.log"

log_line() {
    local message="$1"
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] [refresh-loop] ${message}"
    echo "$line"
    printf '%s\n' "$line" >> "$LOG_FILE" 2>/dev/null || true
}

get_refresh_time() {
    if [ -f "$CONFIG_FILE" ]; then
        value=$(grep '^RefreshTime=' "$CONFIG_FILE" | cut -d'=' -f2)

        if [[ "$value" =~ ^[0-9]+$ ]] && [ "$value" -ge 1 ]; then
            echo "$value"
            return
        fi
    fi

    echo 30
}

export DISPLAY=:0
export XAUTHORITY=/home/pi/.Xauthority

log_line "Refresh loop gestart."

while true; do
    if ! xdotool key F5; then
        log_line "F5 refresh mislukte."
    fi
    sleep "$(get_refresh_time)"
done
