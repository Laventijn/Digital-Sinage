# Productieprofiel - Kiosk + Apache

Dit profiel is bedoeld voor een stabiele Raspberry Pi kiosk-opstelling met webdashboard.

## 1. Gewenste actieve services

- `apache2.service`: AAN
- `kiosk.service`: AAN
- `kiosk-sequence.service`: AAN als je sequence planning gebruikt
- `refresh.service`: optioneel (alleen gebruiken als je F5-loop wilt)

Aanbevolen basis:

```bash
sudo systemctl enable apache2 kiosk.service kiosk-sequence.service
sudo systemctl start apache2 kiosk.service kiosk-sequence.service
```

## 2. Cache-opkuis: efficiënt en instelbaar via website

De cache-opkuis draait via cron elke 5 minuten, maar voert enkel effectief opkuis uit wanneer `CacheInterval` is verlopen.

- Interval komt uit `/etc/default/kiosk.conf`
- Waarde is in **uren**, met decimalen toegestaan
- Voorbeelden:
  - `0.25` = 15 minuten
  - `0.5` = 30 minuten
  - `1` = 60 minuten
  - `0` = uitgeschakeld

Dit combineert:
- snelle respons op wijzigingen
- geen onnodige herstarts

## 3. Website instelling (dashboard)

In het dashboard:

- veld: **Cache legen interval (uur, decimaal toegestaan)**
- voorbeeld invullen: `0.25` voor snelle opkuis
- klik: **Opslaan en toepassen**

Hierdoor wordt `CacheInterval` in `kiosk.conf` bijgewerkt.

## 4. Cron controle

Controleer op de Pi:

```bash
crontab -l
```

Verwachte regel:

```bash
*/5 * * * * /home/pi/refresh_chromium.sh >> /home/pi/refresh_chromium_log.txt 2>&1
```

## 5. Gezondheid check

```bash
systemctl status apache2 kiosk.service kiosk-sequence.service
sudo tail -n 80 /home/pi/refresh_chromium_log.txt
```

## 6. Praktische waarden

- Standaard schoolomgeving: `CacheInterval=0.5`
- Zeer dynamische content: `CacheInterval=0.25`
- Stabiele website met weinig wijzigingen: `CacheInterval=1` of hoger
