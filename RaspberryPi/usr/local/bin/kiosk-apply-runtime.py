#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Any
from urllib.error import URLError
from urllib.parse import quote
from urllib.request import Request, urlopen


CONFIG_FILE = Path("/etc/default/kiosk.conf")
LOG_FILE = Path("/home/pi/kiosk-runtime.log")
DEBUG_BASE_URL = "http://127.0.0.1:9222"
KIOSK_RESTART_COMMAND = ["/bin/systemctl", "restart", "kiosk.service"]
XDOTOOL_BINARY = "/usr/bin/xdotool"
DISPLAY_VALUE = ":0"
XAUTHORITY_PATH = "/home/pi/.Xauthority"
PI_HOME = "/home/pi"


def log(message: str, **context: Any) -> None:
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    parts = [f"[{timestamp}] [runtime-apply] {message.strip()}"]
    if context:
        pairs = []
        for key, value in context.items():
            if isinstance(value, (dict, list)):
                value = json.dumps(value, ensure_ascii=True, separators=(",", ":"))
            pairs.append(f"{key}={value}")
        parts.append(" ".join(pairs))

    line = " ".join(part for part in parts if part)
    print(line, flush=True)

    try:
        LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
        with LOG_FILE.open("a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except OSError:
        pass


def read_kiosk_url_from_config() -> str:
    if not CONFIG_FILE.exists():
        return ""

    try:
        for raw_line in CONFIG_FILE.read_text(encoding="utf-8").splitlines():
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = [part.strip() for part in line.split("=", 1)]
            if key == "KioskURL":
                return value
    except OSError:
        return ""

    return ""


def fetch_json(path: str, method: str = "GET") -> Any:
    request = Request(DEBUG_BASE_URL + path, method=method)
    with urlopen(request, timeout=4) as response:
        payload = response.read().decode("utf-8")
    return json.loads(payload)


def call_endpoint(path: str, method: str = "GET") -> bool:
    request = Request(DEBUG_BASE_URL + path, method=method)
    with urlopen(request, timeout=4):
        return True


def xdotool_env() -> dict[str, str]:
    env = dict(os.environ)
    env["DISPLAY"] = DISPLAY_VALUE
    env["XAUTHORITY"] = XAUTHORITY_PATH
    env["HOME"] = PI_HOME
    return env


def run_xdotool(args: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [XDOTOOL_BINARY, *args],
        check=True,
        capture_output=True,
        text=True,
        env=xdotool_env(),
    )


def list_page_targets() -> list[dict[str, Any]]:
    try:
        targets = fetch_json("/json/list")
    except (URLError, TimeoutError, json.JSONDecodeError, OSError):
        return []

    if not isinstance(targets, list):
        return []

    return [
        target
        for target in targets
        if isinstance(target, dict) and str(target.get("type", "")).strip() == "page"
    ]


def find_visible_chromium_window() -> str:
    pid_patterns = [
        ["/usr/bin/pgrep", "-n", "-u", "pi", "-f", "chromium-browser"],
        ["/usr/bin/pgrep", "-n", "-u", "pi", "-f", "chromium"],
    ]

    for cmd in pid_patterns:
        try:
            pid_result = subprocess.run(cmd, check=True, capture_output=True, text=True)
        except (FileNotFoundError, subprocess.CalledProcessError):
            continue

        pid = pid_result.stdout.strip()
        if not pid:
            continue

        try:
            result = run_xdotool(["search", "--onlyvisible", "--pid", pid])
        except (FileNotFoundError, subprocess.CalledProcessError):
            continue

        window_ids = [line.strip() for line in result.stdout.splitlines() if line.strip()]
        if window_ids:
            return window_ids[-1]

    search_patterns = [
        ["search", "--onlyvisible", "--class", "chromium"],
        ["search", "--onlyvisible", "--class", "chromium-browser"],
        ["search", "--onlyvisible", "--classname", "Chromium"],
        ["search", "--onlyvisible", "--name", "Chromium"],
    ]

    for args in search_patterns:
        try:
            result = run_xdotool(args)
        except (FileNotFoundError, subprocess.CalledProcessError):
            continue

        window_ids = [line.strip() for line in result.stdout.splitlines() if line.strip()]
        if window_ids:
            return window_ids[-1]

    return ""


def create_target(url: str) -> dict[str, Any] | None:
    encoded_url = quote(url, safe="")

    for method in ("PUT", "GET"):
        try:
            payload = fetch_json(f"/json/new?{encoded_url}", method=method)
        except (URLError, TimeoutError, json.JSONDecodeError, OSError):
            continue

        if isinstance(payload, dict):
            return payload

    return None


def apply_via_devtools(url: str) -> bool:
    old_targets = list_page_targets()
    if not old_targets:
        log("DevTools endpoint bereikbaar, maar er is geen actieve browserpagina gevonden.")
        return False

    new_target = create_target(url)
    if not isinstance(new_target, dict):
        log("Nieuwe background target kon niet via DevTools worden geopend.", strategy="devtools")
        return False

    new_target_id = str(new_target.get("id", "")).strip()
    if not new_target_id:
        log("Nieuwe DevTools target heeft geen geldige id.", strategy="devtools")
        return False

    time.sleep(2.0)

    try:
        call_endpoint(f"/json/activate/{quote(new_target_id, safe='')}")
    except (URLError, TimeoutError, OSError):
        log("Nieuwe DevTools target kon niet worden geactiveerd.", strategy="devtools", target_id=new_target_id)
        return False

    time.sleep(0.6)

    for target in old_targets:
        old_target_id = str(target.get("id", "")).strip()
        if not old_target_id or old_target_id == new_target_id:
            continue

        try:
            call_endpoint(f"/json/close/{quote(old_target_id, safe='')}")
        except (URLError, TimeoutError, OSError):
            log("Oude DevTools target kon niet worden gesloten.", strategy="devtools", target_id=old_target_id)

    log("Runtime URL via DevTools omgezet.", strategy="devtools", url=url, old_targets=len(old_targets))
    return True


def apply_via_xdotool(url: str) -> bool:
    try:
        window_id = find_visible_chromium_window()
        if not window_id:
            log(
                "Geen zichtbaar Chromium venster gevonden voor xdotool.",
                strategy="xdotool",
                display=xdotool_env().get("DISPLAY", ""),
                xauthority=xdotool_env().get("XAUTHORITY", ""),
            )
            return False

        run_xdotool(["windowraise", window_id])
        run_xdotool(["windowactivate", "--sync", window_id])
        time.sleep(0.25)
        run_xdotool(["key", "--clearmodifiers", "--window", window_id, "Escape"])
        time.sleep(0.15)
        run_xdotool(["key", "--clearmodifiers", "--window", window_id, "ctrl+l"])
        time.sleep(0.15)
        run_xdotool(["type", "--window", window_id, "--delay", "8", url])
        run_xdotool(["key", "--clearmodifiers", "--window", window_id, "Return"])
        time.sleep(0.2)
        log("Runtime URL via xdotool omgezet.", strategy="xdotool", url=url, window_id=window_id)
        return True
    except (FileNotFoundError, subprocess.CalledProcessError) as exc:
        log("Runtime URL kon niet via xdotool worden omgezet.", strategy="xdotool", error=str(exc))
        return False


def apply_via_restart(url: str) -> bool:
    try:
        subprocess.run(KIOSK_RESTART_COMMAND, check=True)
        log("Kiosk restart uitgevoerd als fallback.", strategy="restart", url=url)
        return True
    except subprocess.CalledProcessError as exc:
        log("Kiosk restart fallback mislukte.", strategy="restart", url=url, error=str(exc))
        return False


def apply_runtime_url(url: str, reason: str) -> bool:
    url = url.strip()
    if not url:
        log("Lege runtime URL ontvangen, wijziging overgeslagen.", reason=reason)
        return False

    log("Runtime apply gestart.", reason=reason, url=url)

    if apply_via_xdotool(url):
        return True

    return apply_via_restart(url)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Pas de huidige kiosk runtime URL toe zonder onnodige restart.")
    parser.add_argument("url", nargs="?", help="Runtime URL om toe te passen. Laat leeg om uit kiosk.conf te lezen.")
    parser.add_argument("--from-config", action="store_true", help="Lees de runtime URL uit /etc/default/kiosk.conf.")
    parser.add_argument("--reason", default="manual", help="Logreden voor de wijziging.")
    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    args = parse_args(argv)
    url = args.url.strip() if isinstance(args.url, str) else ""

    if args.from_config or url == "":
        url = read_kiosk_url_from_config()

    return 0 if apply_runtime_url(url, args.reason) else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
