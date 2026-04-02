#!/bin/bash

echo "==== Voor opkuis cache: $(date) ====" >> /home/pi/refresh_chromium_log.txt
df -h >> /home/pi/refresh_chromium_log.txt

sudo systemctl stop kiosk.service
rm -rf /home/pi/.cache/chromium/
sudo systemctl start kiosk.service

echo "==== Na opkuis cache: $(date) ====" >> /home/pi/refresh_chromium_log.txt
df -h >> /home/pi/refresh_chromium_log.txt
