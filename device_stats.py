#!/usr/bin/env python3
"""
RoadShield — device_stats.py
================================
Periodically reads local CPU / RAM / disk / temperature usage and reports
it to the Laravel dashboard so the "Test Mode — Device Resources" panel
has live data to show.

POSTs to: {LARAVEL_URL}/api/device-agent/heartbeat
"""

import time
import json
import logging
import platform
import socket
import sys

import requests

try:
    import psutil
except ImportError:
    print("psutil is required: pip3 install psutil", file=sys.stderr)
    sys.exit(1)

# ─── Configuration ──────────────────────────────────────────────────────────
LARAVEL_URL         = "https://controlroom.roadshield.ae"
SURVEILLANCE_TOKEN  = "b8e2ed9ae5def597e6a59f2801fca19fa758ab1a0cd3e9900b708b3aa357bc3c"
JETSON_NAME         = "rock1"  # must match the device 'id' in config/surveillance.php
HEARTBEAT_INTERVAL  = 10       # seconds

# Allow CLI overrides (e.g. passed from connect-to-server.sh)
for arg in sys.argv:
    if arg.startswith("--url="):
        LARAVEL_URL = arg.split("=", 1)[1]
    elif arg.startswith("--token="):
        SURVEILLANCE_TOKEN = arg.split("=", 1)[1]
    elif arg.startswith("--jetson-name="):
        JETSON_NAME = arg.split("=", 1)[1]
    elif arg.startswith("--interval="):
        HEARTBEAT_INTERVAL = int(arg.split("=", 1)[1])

# ─── Logging ────────────────────────────────────────────────────────────────
LOG_FILE = "/tmp/device-stats.log"
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [device-stats] %(levelname)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger(__name__)
try:
    file_handler = logging.FileHandler(LOG_FILE)
    file_handler.setFormatter(logging.Formatter("%(asctime)s [device-stats] %(levelname)s: %(message)s", "%Y-%m-%d %H:%M:%S"))
    logging.getLogger().addHandler(file_handler)
except Exception as e:
    log.warning(f"Could not initialize logging to {LOG_FILE}: {e}")

START_TIME = time.time()


def read_temperature() -> int:
    """
    Best-effort CPU/SoC temperature in whole degrees Celsius.
    Tries psutil sensors first (works on most boards), then falls back to
    reading /sys/class/thermal directly (Jetson / generic ARM boards).
    """
    try:
        temps = psutil.sensors_temperatures()
        for entries in temps.values():
            for entry in entries:
                if entry.current:
                    return int(round(entry.current))
    except Exception:
        pass

    try:
        with open("/sys/class/thermal/thermal_zone0/temp") as f:
            millideg = int(f.read().strip())
            return int(round(millideg / 1000))
    except Exception:
        pass

    return 0


def collect_stats() -> dict:
    cpu = int(round(psutil.cpu_percent(interval=1)))
    ram = int(round(psutil.virtual_memory().percent))
    disk = int(round(psutil.disk_usage("/").percent))
    temperature = read_temperature()
    uptime = int(time.time() - psutil.boot_time())

    return {
        "jetson_id": JETSON_NAME,
        "hostname": socket.gethostname(),
        "agent_version": "1.0",
        "online": True,
        "uptime": uptime,
        "cpu": cpu,
        "ram": ram,
        "disk": disk,
        "temperature": temperature,
    }


def send_heartbeat(stats: dict) -> bool:
    url = f"{LARAVEL_URL}/api/device-agent/heartbeat"
    headers = {
        "Authorization": f"Bearer {SURVEILLANCE_TOKEN}",
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    try:
        r = requests.post(url, headers=headers, json=stats, timeout=8)
        if r.status_code == 200:
            log.info(
                f"Heartbeat sent: cpu={stats['cpu']}% ram={stats['ram']}% "
                f"disk={stats['disk']}% temp={stats['temperature']}°C"
            )
            return True
        log.warning(f"Heartbeat rejected: HTTP {r.status_code} — {r.text[:200]}")
        return False
    except Exception as e:
        log.warning(f"Heartbeat failed: {e}")
        return False


def main():
    log.info("=" * 60)
    log.info("RoadShield Device Stats Agent")
    log.info(f"  Server : {LARAVEL_URL}")
    log.info(f"  Device : {JETSON_NAME}")
    log.info(f"  Every  : {HEARTBEAT_INTERVAL}s")
    log.info("=" * 60)

    while True:
        try:
            stats = collect_stats()
            send_heartbeat(stats)
        except Exception as e:
            log.error(f"Unexpected error collecting/sending stats: {e}")
        time.sleep(HEARTBEAT_INTERVAL)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log.info("Stopped.")
