#!/usr/bin/env python3
from __future__ import annotations

import argparse
import base64
import json
import os
import re
import subprocess
import sys
import tempfile
import time
from datetime import datetime
from typing import Any
from urllib.parse import parse_qs, urlencode, urlparse
from zoneinfo import ZoneInfo


CONFIG_FILE = "/etc/default/kiosk.conf"
PRESETS_FILE = "/etc/default/kiosk-presets.json"
LOG_FILE = "/home/pi/kiosk-runtime.log"
RUNTIME_APPLY_COMMAND = ["/usr/local/bin/kiosk-apply-runtime.py", "--from-config", "--reason", "sequence-watcher"]
KIOSK_START_COMMAND = ["/bin/systemctl", "start", "kiosk.service"]
KIOSK_RESTART_COMMAND = ["/bin/systemctl", "restart", "kiosk.service"]
KIOSK_STOP_COMMAND = ["/bin/systemctl", "stop", "kiosk.service"]
DAEMON_RELOAD_COMMAND = ["/bin/systemctl", "daemon-reload"]
CHROMIUM_KILL_COMMAND = ["/usr/bin/pkill", "-u", "pi", "-f", "chromium"]
POLL_SECONDS = 15
DEFAULT_TIMEZONE = "Europe/Brussels"
KIOSK_OFF_OVERRIDE_DIR = "/run/systemd/system/kiosk.service.d"
KIOSK_OFF_OVERRIDE_FILE = os.path.join(KIOSK_OFF_OVERRIDE_DIR, "10-off-hours.conf")
KIOSK_OFF_OVERRIDE_CONTENT = "[Service]\nRestart=no\n"
DISPLAY_ENV = {
    "DISPLAY": ":0",
    "XAUTHORITY": "/home/pi/.Xauthority",
    "HOME": "/home/pi",
}
LAST_OPERATING_STATE: str | None = None


def log(message: str) -> None:
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{timestamp}] [sequence-watcher] {message}"
    print(line, flush=True)

    try:
        with open(LOG_FILE, "a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except OSError:
        pass


def looks_like_google_slides(url: str) -> bool:
    return re.match(r"^https?://docs\.google\.com/presentation/d/[A-Za-z0-9_-]+", url.strip(), re.IGNORECASE) is not None


def extract_google_slides_id(url: str) -> str:
    match = re.match(r"^https?://docs\.google\.com/presentation/d/([A-Za-z0-9_-]+)", url.strip(), re.IGNORECASE)
    return match.group(1) if match else ""


def extract_sequence_key_from_url(url: str) -> str:
    query = parse_qs(urlparse(url.strip()).query)
    return (query.get("preset") or [""])[0].strip()


def urls_refer_to_same_preset(left: str, right: str) -> bool:
    left = left.strip()
    right = right.strip()
    if not left or not right:
        return False
    if left == right:
        return True

    left_slides = extract_google_slides_id(left)
    right_slides = extract_google_slides_id(right)
    if left_slides and left_slides == right_slides:
        return True

    left_sequence = extract_sequence_key_from_url(left)
    right_sequence = extract_sequence_key_from_url(right)
    return bool(left_sequence and left_sequence == right_sequence)


def detect_system_timezone() -> str:
    timezone_name = ""
    if os.path.exists("/etc/timezone"):
        try:
            with open("/etc/timezone", "r", encoding="utf-8") as handle:
                timezone_name = handle.read().strip()
        except OSError:
            timezone_name = ""

    if not timezone_name:
        timezone_name = DEFAULT_TIMEZONE

    try:
        ZoneInfo(timezone_name)
        return timezone_name
    except Exception:
        return DEFAULT_TIMEZONE


def normalize_timezone(value: str) -> str:
    value = value.strip()
    if not value:
        return detect_system_timezone()

    try:
        ZoneInfo(value)
        return value
    except Exception:
        return detect_system_timezone()


def normalize_source_type(preset_type: str, url: str = "") -> str:
    preset_type = preset_type.strip().lower()
    if preset_type == "presentation" or looks_like_google_slides(url):
        return "presentation"
    return "website"


def normalize_preset_type(preset: dict[str, Any]) -> str:
    return normalize_source_type(str(preset.get("type", "")).strip(), str(preset.get("url", "")).strip())


def normalize_sequence_items(raw_sequence: Any) -> list[dict[str, str]]:
    if not isinstance(raw_sequence, list):
        return []

    items: list[dict[str, str]] = []
    for row in raw_sequence:
        if not isinstance(row, dict):
            continue

        preset_url = str(row.get("preset_url", "")).strip()
        start_time = str(row.get("start_time", "")).strip()
        stop_time = str(row.get("stop_time", "")).strip()

        if not preset_url or not re.match(r"^\d{2}:\d{2}$", start_time) or not re.match(r"^\d{2}:\d{2}$", stop_time):
            continue

        items.append(
            {
                "preset_url": preset_url,
                "start_time": start_time,
                "stop_time": stop_time,
            }
        )

    return items


def encode_sequence_config_value(sequence_items: list[dict[str, str]]) -> str:
    normalized = normalize_sequence_items(sequence_items)
    if not normalized:
        return ""

    try:
        return base64.b64encode(
            json.dumps(normalized, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        ).decode("ascii")
    except Exception:
        return ""


def decode_sequence_config_value(encoded: str) -> list[dict[str, str]]:
    encoded = encoded.strip()
    if not encoded:
        return []

    try:
        decoded = base64.b64decode(encoded.encode("ascii"), validate=True).decode("utf-8")
        parsed = json.loads(decoded)
    except Exception:
        return []

    return normalize_sequence_items(parsed)


def build_google_slides_present_url(url: str, slide_start: bool, slide_loop: bool, slide_delay_ms: int) -> str:
    url = url.strip()
    match = re.match(r"^https?://docs\.google\.com/presentation/d/([A-Za-z0-9_-]+)", url, re.IGNORECASE)
    if match:
        presentation_id = match.group(1)
        params = urlencode(
            {
                "start": "true" if slide_start else "false",
                "loop": "true" if slide_loop else "false",
                "delayms": str(slide_delay_ms),
            }
        )
        return f"https://docs.google.com/presentation/d/{presentation_id}/present?{params}"

    cleaned = re.sub(r"([?&])(start|loop|delayms)=[^&]*", "", url, flags=re.IGNORECASE)
    cleaned = re.sub(r"[?&]+$", "", cleaned)
    separator = "?" if "?" not in cleaned else "&"
    return (
        f"{cleaned}{separator}"
        f"start={'true' if slide_start else 'false'}&"
        f"loop={'true' if slide_loop else 'false'}&"
        f"delayms={slide_delay_ms}"
    )


def normalize_content_url(preset_type: str, url: str, slide_start: bool, slide_loop: bool, slide_seconds: int) -> str:
    url = url.strip()
    if preset_type == "presentation":
        return build_google_slides_present_url(url, slide_start, slide_loop, slide_seconds * 1000)
    return url


def load_presets() -> list[dict[str, Any]]:
    if not os.path.exists(PRESETS_FILE):
        return []

    try:
        with open(PRESETS_FILE, "r", encoding="utf-8") as handle:
            raw = handle.read().strip()
    except OSError:
        return []

    if not raw:
        return []

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        log("Kon kiosk-presets.json niet lezen.")
        return []

    if not isinstance(decoded, list):
        return []

    presets: list[dict[str, Any]] = []
    for row in decoded:
        if not isinstance(row, dict):
            continue

        name = str(row.get("name", "")).strip()
        url = str(row.get("url", "")).strip()
        source_url = str(row.get("source_url", "")).strip()
        sequence_key = str(row.get("sequence_key", "")).strip()
        legacy_sequence = str(row.get("type", "")).strip().lower() == "sequence"
        effective_url = source_url if legacy_sequence and source_url else url
        effective_type = (
            normalize_source_type(str(row.get("source_type", "")).strip(), effective_url)
            if legacy_sequence
            else normalize_source_type(str(row.get("type", "")).strip(), effective_url)
        )

        if not name or not effective_url:
            continue

        presets.append(
            {
                "name": name,
                "url": effective_url,
                "description": str(row.get("description", "")).strip(),
                "type": effective_type,
                "sequence_key": sequence_key,
                "legacy_sequence_url": url if legacy_sequence else "",
                "sequence": normalize_sequence_items(row.get("sequence", [])),
            }
        )

    return presets


def config_string_to_bool(value: str, default: bool = True) -> bool:
    normalized = value.strip().lower()
    if normalized in {"true", "1", "yes", "on"}:
        return True
    if normalized in {"false", "0", "no", "off"}:
        return False
    return default


def load_config() -> dict[str, Any]:
    config: dict[str, Any] = {
        "KioskURL": "",
        "KioskMode": "website",
        "SelectedPresetUrl": "",
        "SequenceKey": "",
        "ResolvedPresetUrl": "",
        "SequenceData": "",
        "SequenceItems": [],
        "SequenceItemsDefined": False,
        "Timezone": detect_system_timezone(),
        "SlideStart": True,
        "SlideLoop": True,
        "SlideDelay": 10000,
        "RefreshTime": 30,
        "CacheInterval": 2,
        "StartTime": "",
        "StopTime": "",
    }

    if not os.path.exists(CONFIG_FILE):
        return config

    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as handle:
            lines = handle.readlines()
    except OSError:
        return config

    for line in lines:
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, value = [part.strip() for part in stripped.split("=", 1)]
        if key in {"KioskURL", "KioskMode", "SelectedPresetUrl", "SequenceKey", "ResolvedPresetUrl", "StartTime", "StopTime"}:
            config[key] = value
        elif key == "SequenceData":
            config["SequenceData"] = value
            config["SequenceItems"] = decode_sequence_config_value(value)
            config["SequenceItemsDefined"] = True
        elif key == "Timezone":
            config[key] = normalize_timezone(value)
        elif key == "SlideStart":
            config[key] = config_string_to_bool(value, True)
        elif key == "SlideLoop":
            config[key] = config_string_to_bool(value, True)
        elif key == "SlideDelay":
            config[key] = max(1000, int(value)) if value.isdigit() else config["SlideDelay"]
        elif key == "RefreshTime":
            config[key] = max(0, int(value)) if value.isdigit() else config["RefreshTime"]
        elif key == "CacheInterval":
            config[key] = max(0, int(value)) if value.isdigit() else config["CacheInterval"]

    return config


def write_config(config: dict[str, Any]) -> None:
    file_mode = 0o664
    file_uid = -1
    file_gid = -1
    if os.path.exists(CONFIG_FILE):
        try:
            stat_result = os.stat(CONFIG_FILE)
            file_mode = stat_result.st_mode & 0o777
            file_uid = stat_result.st_uid
            file_gid = stat_result.st_gid
        except OSError:
            pass

    lines = [
        "# ==========================================",
        "# Kiosk configuratiebestand",
        "# ==========================================",
        "",
        "# Runtime doel",
        f"KioskURL={str(config.get('KioskURL', '')).strip()}",
        f"KioskMode={str(config.get('KioskMode', 'website')).strip().lower() or 'website'}",
        f"SelectedPresetUrl={str(config.get('SelectedPresetUrl', '')).strip()}",
        f"SequenceKey={str(config.get('SequenceKey', '')).strip()}",
        f"ResolvedPresetUrl={str(config.get('ResolvedPresetUrl', '')).strip()}",
        f"SequenceData={encode_sequence_config_value(list(config.get('SequenceItems', [])))}",
        f"Timezone={normalize_timezone(str(config.get('Timezone', detect_system_timezone())))}",
        "",
        "# Google Slides opties",
        f"SlideStart={'true' if bool(config.get('SlideStart', True)) else 'false'}",
        f"SlideLoop={'true' if bool(config.get('SlideLoop', True)) else 'false'}",
        f"SlideDelay={max(1000, int(config.get('SlideDelay', 10000)))}",
        "",
        "# Refresh instellingen",
        f"RefreshTime={max(0, int(config.get('RefreshTime', 30)))}",
        f"CacheInterval={max(0, int(config.get('CacheInterval', 2)))}",
        "",
        "# Tijdschema (optioneel)",
    ]

    start_time = str(config.get("StartTime", "")).strip()
    stop_time = str(config.get("StopTime", "")).strip()
    if start_time:
        lines.append(f"StartTime={start_time}")
    else:
        lines.append("#StartTime=08:00")
    if stop_time:
        lines.append(f"StopTime={stop_time}")
    else:
        lines.append("#StopTime=18:00")

    content = "\n".join(lines) + "\n"
    directory = os.path.dirname(CONFIG_FILE) or "."
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=directory, delete=False) as handle:
        handle.write(content)
        temp_name = handle.name

    os.chmod(temp_name, file_mode)
    if file_uid >= 0 and file_gid >= 0:
        try:
            os.chown(temp_name, file_uid, file_gid)
        except PermissionError:
            pass
    os.replace(temp_name, CONFIG_FILE)


def find_preset_by_sequence_key(presets: list[dict[str, Any]], sequence_key: str) -> dict[str, Any] | None:
    sequence_key = sequence_key.strip()
    for preset in presets:
        if str(preset.get("sequence_key", "")).strip() == sequence_key:
            return preset
    return None


def find_preset_by_url(presets: list[dict[str, Any]], url: str) -> dict[str, Any] | None:
    candidate_sequence_key = extract_sequence_key_from_url(url)
    for preset in presets:
        preset_url = str(preset.get("url", "")).strip()
        legacy_sequence_url = str(preset.get("legacy_sequence_url", "")).strip()
        preset_sequence_key = str(preset.get("sequence_key", "")).strip()

        if (
            urls_refer_to_same_preset(preset_url, url)
            or (legacy_sequence_url and urls_refer_to_same_preset(legacy_sequence_url, url))
            or (candidate_sequence_key and preset_sequence_key and candidate_sequence_key == preset_sequence_key)
        ):
            return preset
    return None


def resolve_configured_sequence_items(config: dict[str, Any], selected_preset: dict[str, Any] | None) -> list[dict[str, str]]:
    configured_items = normalize_sequence_items(config.get("SequenceItems", []))
    if configured_items or bool(config.get("SequenceItemsDefined", False)):
        return configured_items

    if isinstance(selected_preset, dict):
        return normalize_sequence_items(selected_preset.get("sequence", []))

    return []


def time_to_minutes(value: str) -> int:
    hours, minutes = value.split(":", 1)
    return (int(hours) * 60) + int(minutes)


def time_in_range(start_time: str, stop_time: str, now: datetime) -> bool:
    start_minutes = time_to_minutes(start_time)
    stop_minutes = time_to_minutes(stop_time)
    current_minutes = (now.hour * 60) + now.minute

    if start_minutes == stop_minutes:
        return False
    if start_minutes < stop_minutes:
        return start_minutes <= current_minutes < stop_minutes
    return current_minutes >= start_minutes or current_minutes < stop_minutes


def display_env() -> dict[str, str]:
    env = dict(os.environ)
    env.update(DISPLAY_ENV)
    return env


def service_is_active(service_name: str) -> bool:
    result = subprocess.run(
        ["/bin/systemctl", "is-active", "--quiet", service_name],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    return result.returncode == 0


def off_hours_override_present() -> bool:
    return os.path.exists(KIOSK_OFF_OVERRIDE_FILE)


def enable_off_hours_override() -> bool:
    try:
        os.makedirs(KIOSK_OFF_OVERRIDE_DIR, exist_ok=True)
        with open(KIOSK_OFF_OVERRIDE_FILE, "w", encoding="utf-8") as handle:
            handle.write(KIOSK_OFF_OVERRIDE_CONTENT)
    except OSError:
        log("Kiosk off-hours override schrijven mislukte.")
        return False

    return run_command(DAEMON_RELOAD_COMMAND, "Systemd herladen voor off-hours override")


def disable_off_hours_override() -> bool:
    if not off_hours_override_present():
        return True

    try:
        os.remove(KIOSK_OFF_OVERRIDE_FILE)
    except OSError:
        log("Kiosk off-hours override verwijderen mislukte.")
        return False

    return run_command(DAEMON_RELOAD_COMMAND, "Systemd herladen na off-hours override")


def run_best_effort(commands: list[tuple[list[str], dict[str, str] | None]], description: str) -> bool:
    success = False
    for command, env in commands:
        try:
            subprocess.run(
                command,
                check=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                env=env,
            )
            success = True
        except (FileNotFoundError, subprocess.CalledProcessError):
            continue

    if success:
        log(f"{description} uitgevoerd.")
    else:
        log(f"{description} mislukte.")

    return success


def has_operating_schedule(config: dict[str, Any]) -> bool:
    start_time = str(config.get("StartTime", "")).strip()
    stop_time = str(config.get("StopTime", "")).strip()
    return bool(
        re.match(r"^\d{2}:\d{2}$", start_time)
        and re.match(r"^\d{2}:\d{2}$", stop_time)
        and start_time != stop_time
    )


def is_operating_time(config: dict[str, Any], now: datetime) -> bool:
    if not has_operating_schedule(config):
        return True

    return time_in_range(str(config.get("StartTime", "")).strip(), str(config.get("StopTime", "")).strip(), now)


def enforce_operating_schedule(config: dict[str, Any]) -> tuple[bool, datetime]:
    global LAST_OPERATING_STATE

    timezone_name = normalize_timezone(str(config.get("Timezone", detect_system_timezone())))
    now = datetime.now(ZoneInfo(timezone_name))
    should_be_on = is_operating_time(config, now)
    kiosk_active = service_is_active("kiosk.service")
    off_override_active = off_hours_override_present()

    if should_be_on:
        if LAST_OPERATING_STATE != "on" or off_override_active:
            if off_override_active:
                disable_off_hours_override()
            run_best_effort(
                [
                    (["/usr/bin/vcgencmd", "display_power", "1"], None),
                    (["/usr/bin/xset", "dpms", "force", "on"], display_env()),
                ],
                "Display ingeschakeld volgens tijdschema",
            )
        if not kiosk_active:
            run_command(KIOSK_START_COMMAND, "Kiosk service gestart volgens tijdschema")
            time.sleep(2.0)
        LAST_OPERATING_STATE = "on"
        return True, now

    if LAST_OPERATING_STATE != "off" or kiosk_active or not off_override_active:
        if not off_override_active:
            enable_off_hours_override()
        if kiosk_active:
            run_command(KIOSK_STOP_COMMAND, "Kiosk service gestopt buiten tijdschema")
        run_best_effort(
            [
                (CHROMIUM_KILL_COMMAND, None),
            ],
            "Chromium afgesloten buiten tijdschema",
        )
        run_best_effort(
            [
                (["/usr/bin/xset", "dpms", "force", "off"], display_env()),
                (["/usr/bin/vcgencmd", "display_power", "0"], None),
            ],
            "Display uitgeschakeld buiten tijdschema",
        )

    LAST_OPERATING_STATE = "off"
    return False, now


def resolve_active_sequence_slot(sequence_items: list[dict[str, str]], now: datetime) -> dict[str, str] | None:
    for slot in normalize_sequence_items(sequence_items):
        if time_in_range(slot["start_time"], slot["stop_time"], now):
            return slot
    return None


def resolve_sequence_slot_target(slot: dict[str, str], presets: list[dict[str, Any]], config: dict[str, Any]) -> dict[str, str] | None:
    slot_preset_url = slot.get("preset_url", "").strip()
    if not slot_preset_url:
        return None

    linked_preset = find_preset_by_url(presets, slot_preset_url)
    slide_start = bool(config.get("SlideStart", True))
    slide_loop = bool(config.get("SlideLoop", True))
    slide_seconds = max(1, int(int(config.get("SlideDelay", 10000)) / 1000))

    if linked_preset is not None:
        linked_url = str(linked_preset.get("url", "")).strip()
        linked_type = normalize_source_type(str(linked_preset.get("type", "")), linked_url)
        return {
            "resolved_preset_url": linked_url,
            "runtime_url": normalize_content_url(linked_type, linked_url, slide_start, slide_loop, slide_seconds),
            "type": linked_type,
            "label": str(linked_preset.get("name", "")).strip(),
        }

    parsed = urlparse(slot_preset_url)
    if not parsed.scheme or not parsed.netloc:
        return None

    direct_type = normalize_source_type("", slot_preset_url)
    return {
        "resolved_preset_url": slot_preset_url,
        "runtime_url": normalize_content_url(direct_type, slot_preset_url, slide_start, slide_loop, slide_seconds),
        "type": direct_type,
        "label": slot_preset_url,
    }


def resolve_selected_preset_target(selected_preset: dict[str, Any], config: dict[str, Any]) -> dict[str, str] | None:
    preset_url = str(selected_preset.get("url", "")).strip()
    parsed = urlparse(preset_url)
    if not preset_url or not parsed.scheme or not parsed.netloc:
        return None

    slide_start = bool(config.get("SlideStart", True))
    slide_loop = bool(config.get("SlideLoop", True))
    slide_seconds = max(1, int(int(config.get("SlideDelay", 10000)) / 1000))
    preset_type = normalize_source_type(str(selected_preset.get("type", "")), preset_url)

    return {
        "resolved_preset_url": preset_url,
        "runtime_url": normalize_content_url(preset_type, preset_url, slide_start, slide_loop, slide_seconds),
        "type": preset_type,
        "label": str(selected_preset.get("name", "")).strip() or preset_url,
    }


def resolve_sequence_runtime_data(
    selected_preset: dict[str, Any],
    sequence_items: list[dict[str, str]],
    presets: list[dict[str, Any]],
    config: dict[str, Any],
) -> dict[str, str]:
    timezone_name = normalize_timezone(str(config.get("Timezone", detect_system_timezone())))
    now = datetime.now(ZoneInfo(timezone_name))
    active_slot = resolve_active_sequence_slot(sequence_items, now)
    resolved_target = resolve_sequence_slot_target(active_slot, presets, config) if active_slot else None
    fallback_target = resolve_selected_preset_target(selected_preset, config)

    if resolved_target is not None:
        return {
            "status": "active",
            "resolved_preset_url": resolved_target["resolved_preset_url"],
            "runtime_url": resolved_target["runtime_url"],
            "type": resolved_target["type"],
            "label": resolved_target["label"],
        }

    if fallback_target is not None:
        return {
            "status": "fallback",
            "resolved_preset_url": fallback_target["resolved_preset_url"],
            "runtime_url": fallback_target["runtime_url"],
            "type": fallback_target["type"],
            "label": fallback_target["label"],
        }

    return {
        "status": "missing",
        "resolved_preset_url": "",
        "runtime_url": "",
        "type": "website",
        "label": "",
    }


def run_command(command: list[str], description: str, env: dict[str, str] | None = None) -> bool:
    try:
        subprocess.run(command, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
        log(f"{description} uitgevoerd.")
        return True
    except subprocess.CalledProcessError:
        log(f"{description} mislukte.")
        return False


def resolve_current_sequence_preset(presets: list[dict[str, Any]], config: dict[str, Any]) -> dict[str, Any] | None:
    candidate_urls = [
        str(config.get("SelectedPresetUrl", "")).strip(),
        str(config.get("ResolvedPresetUrl", "")).strip(),
        str(config.get("KioskURL", "")).strip(),
    ]

    for selected_url in candidate_urls:
        if not selected_url:
            continue

        preset = find_preset_by_url(presets, selected_url)
        if preset is not None:
            return preset

    sequence_key = str(config.get("SequenceKey", "")).strip()
    if sequence_key:
        preset = find_preset_by_sequence_key(presets, sequence_key)
        if preset is not None:
            return preset

    return None


def run_once() -> None:
    config = load_config()
    operating_allowed, _ = enforce_operating_schedule(config)
    if not operating_allowed:
        return

    presets = load_presets()
    selected_preset = resolve_current_sequence_preset(presets, config)
    if selected_preset is None:
        return

    sequence_items = resolve_configured_sequence_items(config, selected_preset)
    if not sequence_items:
        return

    runtime = resolve_sequence_runtime_data(selected_preset, sequence_items, presets, config)
    runtime_url = runtime.get("runtime_url", "").strip()
    resolved_preset_url = runtime.get("resolved_preset_url", "").strip()

    parsed_runtime = urlparse(runtime_url)
    if not runtime_url or not parsed_runtime.scheme or not parsed_runtime.netloc:
        log("Geen geldige override of hoofdpreset target gevonden.")
        return

    old_kiosk_url = str(config.get("KioskURL", "")).strip()
    old_resolved_url = str(config.get("ResolvedPresetUrl", "")).strip()
    old_selected_url = str(config.get("SelectedPresetUrl", "")).strip()
    selected_url = str(selected_preset.get("url", "")).strip()

    changed = (
        old_kiosk_url != runtime_url
        or old_resolved_url != resolved_preset_url
        or old_selected_url != selected_url
    )
    if not changed:
        return

    config["KioskMode"] = "sequence"
    config["SelectedPresetUrl"] = selected_url
    config["SequenceKey"] = ""
    config["ResolvedPresetUrl"] = resolved_preset_url
    config["KioskURL"] = runtime_url
    config["SequenceItems"] = sequence_items
    config["SequenceItemsDefined"] = True
    config["Timezone"] = normalize_timezone(str(config.get("Timezone", detect_system_timezone())))

    write_config(config)
    if old_kiosk_url != runtime_url:
        apply_ok = run_command(RUNTIME_APPLY_COMMAND, "Runtime apply script")
        if not apply_ok:
            run_command(KIOSK_RESTART_COMMAND, "Kiosk restart")

    log(
        "Sequence target bijgewerkt naar "
        f"{runtime_url} (status: {runtime.get('status', 'missing')}, resolved: {resolved_preset_url or 'geen'})"
    )


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Volg kiosk sequence- en tijdschema-updates.")
    parser.add_argument("--once", action="store_true", help="Voer een enkele controle direct uit en stop daarna.")
    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    args = parse_args(argv)
    log("Kiosk sequence watcher gestart.")

    if args.once:
        try:
            run_once()
            return 0
        except Exception as exc:  # pragma: no cover - safety net for one-shot run
            log(f"Watcher fout: {exc}")
            return 1

    while True:
        try:
            run_once()
        except Exception as exc:  # pragma: no cover - safety net for daemon process
            log(f"Watcher fout: {exc}")
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
