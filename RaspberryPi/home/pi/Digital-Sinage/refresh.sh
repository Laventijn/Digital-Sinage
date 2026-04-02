#!/bin/bash

CONFIG_FILE="/etc/default/kiosk.conf"

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

while true; do
    xdotool key F5
    sleep "$(get_refresh_time)"
done