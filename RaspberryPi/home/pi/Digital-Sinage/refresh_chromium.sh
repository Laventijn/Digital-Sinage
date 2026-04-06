#!/bin/bash

set -u

CONFIG_FILE="/etc/default/kiosk.conf"
LOG_FILE="/home/pi/refresh_chromium_log.txt"
STATE_FILE="/home/pi/.kiosk_cache_last_clear"

get_cache_interval_hours() {
	local value

	if [ -f "$CONFIG_FILE" ]; then
		value=$(grep '^CacheInterval=' "$CONFIG_FILE" | tail -n1 | cut -d'=' -f2 | tr -d '[:space:]')
	fi

	if [[ -z "${value:-}" ]]; then
		echo "2"
		return
	fi

	if [[ "$value" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
		echo "$value"
		return
	fi

	echo "2"
}

hours_to_seconds() {
	awk -v h="$1" 'BEGIN { printf "%d", h * 3600 }'
}

log_line() {
	echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

cache_hours=$(get_cache_interval_hours)
cache_seconds=$(hours_to_seconds "$cache_hours")

if [ "$cache_seconds" -le 0 ]; then
	log_line "Cache-opkuis uitgeschakeld (CacheInterval=${cache_hours})."
	exit 0
fi

now=$(date +%s)
last_run=0
if [ -f "$STATE_FILE" ]; then
	read -r last_run < "$STATE_FILE"
	if [[ ! "$last_run" =~ ^[0-9]+$ ]]; then
		last_run=0
	fi
fi

elapsed=$((now - last_run))
if [ "$elapsed" -lt "$cache_seconds" ]; then
	exit 0
fi

log_line "Start cache-opkuis (interval=${cache_hours}u)."
df -h >> "$LOG_FILE"

rm -rf /home/pi/.cache/chromium/*
sudo /bin/systemctl restart kiosk.service

echo "$now" > "$STATE_FILE"

log_line "Cache-opkuis klaar."
df -h >> "$LOG_FILE"
