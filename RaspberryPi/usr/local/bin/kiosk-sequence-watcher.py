#!/usr/bin/env python3
from __future__ import annotations

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
REFRESH_SCRIPT = "/home/pi/refresh_once.sh"
KIOSK_RESTART_COMMAND = ["/bin/systemctl", "restart", "kiosk.service"]
POLL_SECONDS = 15
DEFAULT_TIMEZONE = "Europe/Brussels"


def log(message: str) -> None:
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{timestamp}] {message}", flush=True)


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
    preset_type = str(preset.get("type", "")).strip().lower()
    if preset_type == "sequence" or preset.get("sequence"):
        return "sequence"
    return normalize_source_type(preset_type, str(preset.get("url", "")).strip())


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
        if not name or not url:
            continue

        presets.append(
            {
                "name": name,
                "url": url,
                "description": str(row.get("description", "")).strip(),
                "type": normalize_preset_type(row),
                "sequence_key": str(row.get("sequence_key", "")).strip(),
                "source_url": str(row.get("source_url", "")).strip(),
                "source_type": normalize_source_type(str(row.get("source_type", "")).strip(), str(row.get("source_url", "")).strip()),
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
    for preset in presets:
        if urls_refer_to_same_preset(str(preset.get("url", "")), url):
            return preset
    return None


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
        if normalize_preset_type(linked_preset) == "sequence":
            return None

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


def resolve_sequence_fallback_target(sequence_preset: dict[str, Any], config: dict[str, Any]) -> dict[str, str] | None:
    source_url = str(sequence_preset.get("source_url", "")).strip()
    parsed = urlparse(source_url)
    if not source_url or not parsed.scheme or not parsed.netloc:
        return None

    slide_start = bool(config.get("SlideStart", True))
    slide_loop = bool(config.get("SlideLoop", True))
    slide_seconds = max(1, int(int(config.get("SlideDelay", 10000)) / 1000))
    source_type = normalize_source_type(str(sequence_preset.get("source_type", "")), source_url)

    return {
        "resolved_preset_url": source_url,
        "runtime_url": normalize_content_url(source_type, source_url, slide_start, slide_loop, slide_seconds),
        "type": source_type,
        "label": str(sequence_preset.get("name", "")).strip() or source_url,
    }


def resolve_sequence_runtime_data(sequence_preset: dict[str, Any], presets: list[dict[str, Any]], config: dict[str, Any]) -> dict[str, str]:
    timezone_name = normalize_timezone(str(config.get("Timezone", detect_system_timezone())))
    now = datetime.now(ZoneInfo(timezone_name))
    active_slot = resolve_active_sequence_slot(sequence_preset.get("sequence", []), now)
    resolved_target = resolve_sequence_slot_target(active_slot, presets, config) if active_slot else None
    fallback_target = resolve_sequence_fallback_target(sequence_preset, config)

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


def run_command(command: list[str], description: str) -> bool:
    try:
        subprocess.run(command, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        log(f"{description} uitgevoerd.")
        return True
    except subprocess.CalledProcessError:
        log(f"{description} mislukte.")
        return False


def resolve_current_sequence_preset(presets: list[dict[str, Any]], config: dict[str, Any]) -> dict[str, Any] | None:
    sequence_key = str(config.get("SequenceKey", "")).strip()
    if sequence_key:
        preset = find_preset_by_sequence_key(presets, sequence_key)
        if preset is not None:
            return preset

    selected_url = str(config.get("SelectedPresetUrl", "")).strip()
    if selected_url:
        preset = find_preset_by_url(presets, selected_url)
        if preset is not None and normalize_preset_type(preset) == "sequence":
            return preset

    return None


def run_once() -> None:
    config = load_config()
    if str(config.get("KioskMode", "website")).strip().lower() != "sequence":
        return

    presets = load_presets()
    sequence_preset = resolve_current_sequence_preset(presets, config)
    if sequence_preset is None:
        log("Sequence modus actief, maar geselecteerde sequence preset is niet gevonden.")
        return

    runtime = resolve_sequence_runtime_data(sequence_preset, presets, config)
    runtime_url = runtime.get("runtime_url", "").strip()
    resolved_preset_url = runtime.get("resolved_preset_url", "").strip()

    parsed_runtime = urlparse(runtime_url)
    if not runtime_url or not parsed_runtime.scheme or not parsed_runtime.netloc:
        log("Geen geldige active of fallback target gevonden voor sequence.")
        return

    old_kiosk_url = str(config.get("KioskURL", "")).strip()
    old_resolved_url = str(config.get("ResolvedPresetUrl", "")).strip()
    old_selected_url = str(config.get("SelectedPresetUrl", "")).strip()
    old_sequence_key = str(config.get("SequenceKey", "")).strip()

    sequence_url = str(sequence_preset.get("url", "")).strip()
    sequence_key = str(sequence_preset.get("sequence_key", "")).strip()

    changed = (
        old_kiosk_url != runtime_url
        or old_resolved_url != resolved_preset_url
        or old_selected_url != sequence_url
        or old_sequence_key != sequence_key
    )
    if not changed:
        return

    config["KioskMode"] = "sequence"
    config["SelectedPresetUrl"] = sequence_url
    config["SequenceKey"] = sequence_key
    config["ResolvedPresetUrl"] = resolved_preset_url
    config["KioskURL"] = runtime_url
    config["Timezone"] = normalize_timezone(str(config.get("Timezone", detect_system_timezone())))

    write_config(config)
    refresh_ok = run_command([REFRESH_SCRIPT], "Refresh script")

    if old_kiosk_url != runtime_url or not refresh_ok:
        run_command(KIOSK_RESTART_COMMAND, "Kiosk restart")

    log(
        "Sequence target bijgewerkt naar "
        f"{runtime_url} (status: {runtime.get('status', 'missing')}, resolved: {resolved_preset_url or 'geen'})"
    )


def main() -> int:
    log("Kiosk sequence watcher gestart.")
    while True:
        try:
            run_once()
        except Exception as exc:  # pragma: no cover - safety net for daemon process
            log(f"Watcher fout: {exc}")
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    sys.exit(main())
