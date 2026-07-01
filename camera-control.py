#!/usr/bin/env python3
"""
RoadShield — camera-control.py
================================
Polls the Laravel API for pending PTZ commands and executes them
via Hikvision ISAPI on the local camera network.

Run alongside connect-to-server.sh:
  python3 camera-control.py

Requirements:
  pip install requests

Architecture:
  Browser → POST /api/surveillance/cameras/{id}/ptz (Laravel)
         ↓ cached in Laravel
  camera-control.py polls GET  /api/surveillance/cameras/{id}/ptz/poll
         → Executes ISAPI on camera (192.168.1.x)
         → POST /api/surveillance/cameras/{id}/ptz/ack (result)
"""

import time
import json
import logging
import requests
import xml.etree.ElementTree as ET
from requests.auth import HTTPDigestAuth

# ─── Configuration ──────────────────────────────────────────────────────────
LARAVEL_URL       = "https://controlroom.dubibid.com"
SURVEILLANCE_TOKEN = "b8e2ed9ae5def597e6a59f2801fca19fa758ab1a0cd3e9900b708b3aa357bc3c"

CAMERAS = {
    "cam1": {"ip": "192.168.1.64", "user": "admin", "password": "hikvision@12", "channel": 1},
    "cam2": {"ip": "192.168.1.65", "user": "admin", "password": "hikvision@12", "channel": 1},
    "cam3": {"ip": "192.168.1.67", "user": "admin", "password": "hikvision@12", "channel": 1},
}
SHOW_LOCAL_VIEWER = True  # Set to True to open a local window showing the transcoded stream
POLL_INTERVAL  = 0.8   # seconds between polls per camera
ISAPI_TIMEOUT  = 3     # seconds for ISAPI request timeout
PTZ_SPEED      = 40    # 0-100 for Hikvision ISAPI

# ─── Quality Settings Mapping ───────────────────────────────────────────────
QUALITY_SETTINGS = {
    "hd": {
        "width": 1280,
        "height": 720,
        "bitrate": "1500k",
        "bufsize": "3000k",
        "source_suffix": ""       # Plays HD stream (camX)
    },
    "sd": {
        "width": 854,
        "height": 480,
        "bitrate": "500k",
        "bufsize": "1000k",
        "source_suffix": "_sub"   # Plays SD sub-stream (camX_sub)
    },
    "ultra": {
        "width": 640,
        "height": 360,
        "bitrate": "150k",
        "bufsize": "300k",
        "source_suffix": "_sub"   # Plays Ultra low sub-stream (camX_sub)
    }
}

# ─── Logging ────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [camera-ctrl] %(levelname)s: %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger(__name__)

# ─── API helpers ─────────────────────────────────────────────────────────────
def api_headers():
    return {
        "Authorization": f"Bearer {SURVEILLANCE_TOKEN}",
        "Content-Type":  "application/json",
        "Accept":        "application/json",
    }


def poll_commands(camera_id: str) -> list:
    """Fetch pending PTZ commands from Laravel cache."""
    url = f"{LARAVEL_URL}/api/surveillance/cameras/{camera_id}/ptz/poll"
    try:
        r = requests.get(url, headers=api_headers(), timeout=5)
        if r.status_code == 200:
            data = r.json()
            return data.get("commands", [])
    except Exception as e:
        log.warning(f"Poll failed for {camera_id}: {e}")
    return []


def poll_settings() -> dict:
    """Fetch camera settings (Quality + FPS) from Laravel."""
    url = f"{LARAVEL_URL}/api/surveillance/cameras/settings"
    try:
        r = requests.get(url, headers=api_headers(), timeout=5)
        if r.status_code == 200:
            data = r.json()
            return data.get("settings", {})
    except Exception as e:
        log.warning(f"Poll settings failed: {e}")
    return {}


def ack_command(camera_id: str, command_id: str, success: bool, error: str = None):
    """Report PTZ command result back to Laravel."""
    url  = f"{LARAVEL_URL}/api/surveillance/cameras/{camera_id}/ptz/ack"
    body = {"command_id": command_id, "success": success, "error": error}
    try:
        requests.post(url, headers=api_headers(), json=body, timeout=5)
    except Exception as e:
        log.warning(f"Ack failed for {camera_id}/{command_id}: {e}")


# ─── Transcoder Manager (FFmpeg Processes) ──────────────────────────────────
import subprocess

class TranscoderManager:
    def __init__(self):
        self.active_processes = {}  # camera_id -> subprocess.Popen
        self.active_viewers   = {}  # camera_id -> subprocess.Popen
        self.current_settings = {}  # camera_id -> {"quality": str, "fps": int}

    def apply_settings(self, camera_id, quality, fps):
        prev = self.current_settings.get(camera_id)
        proc = self.active_processes.get(camera_id)

        settings_changed = not prev or prev["quality"] != quality or prev["fps"] != fps
        process_dead = proc is not None and proc.poll() is not None

        if settings_changed or process_dead or not proc:
            log.info(f"[{camera_id}] Applying transcoding settings: quality={quality.upper()}, fps={fps}")
            self.stop_transcoder(camera_id)
            self.start_transcoder(camera_id, quality, fps)
            self.current_settings[camera_id] = {"quality": quality, "fps": fps}

    def start_transcoder(self, camera_id, quality, fps):
        cfg = QUALITY_SETTINGS.get(quality, QUALITY_SETTINGS["hd"])
        width = cfg["width"]
        height = cfg["height"]
        bitrate = cfg["bitrate"]
        bufsize = cfg["bufsize"]
        suffix = cfg["source_suffix"]

        input_url = f"rtsp://127.0.0.1:8554/{camera_id}{suffix}"
        output_url = f"rtsp://127.0.0.1:8554/{camera_id}_live"

        cmd = [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-rtsp_transport", "tcp",
            "-i", input_url,
            "-vf", f"scale={width}:{height},fps={fps}",
            "-c:v", "libx264", "-preset", "ultrafast", "-tune", "zerolatency",
            "-b:v", bitrate, "-maxrate", bitrate, "-bufsize", bufsize, "-an",
            "-f", "rtsp", output_url
        ]

        try:
            # Start process in background
            proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            self.active_processes[camera_id] = proc
            log.info(f"[{camera_id}] Started FFmpeg dynamic transcoder (PID {proc.pid})")
            
            # Start local viewer if enabled
            if SHOW_LOCAL_VIEWER:
                # Spawn viewer in a background thread or wait slightly so RTSP server initializes the new mountpoint
                import threading
                def launch_viewer_delayed():
                    time.sleep(1.0)
                    if self.active_processes.get(camera_id) == proc: # make sure it hasn't been stopped
                        self.start_viewer(camera_id)
                threading.Thread(target=launch_viewer_delayed, daemon=True).start()

        except Exception as e:
            log.error(f"[{camera_id}] Failed to start FFmpeg: {e}. Make sure 'ffmpeg' is installed.")

    def start_viewer(self, camera_id):
        output_url = f"rtsp://127.0.0.1:8554/{camera_id}_live"
        
        # Try running ffplay with lowest latency parameters
        ffplay_cmd = [
            "ffplay", "-rtsp_transport", "tcp",
            "-fflags", "nobuffer", "-flags", "low_delay",
            "-window_title", f"Transcoded Preview (Local) — {camera_id.upper()}",
            output_url
        ]
        
        try:
            viewer = subprocess.Popen(ffplay_cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            self.active_viewers[camera_id] = viewer
            log.info(f"[{camera_id}] Opened local transcoded preview window (ffplay PID {viewer.pid})")
        except FileNotFoundError:
            # Fallback to GStreamer autovideosink if ffplay is not found
            gst_cmd = [
                "gst-launch-1.0", "-v", "rtspsrc", f"location={output_url}", "latency=0", "protocols=tcp",
                "!", "decodebin", "!", "videoconvert", "!", "autovideosink", "sync=false"
            ]
            try:
                viewer = subprocess.Popen(gst_cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                self.active_viewers[camera_id] = viewer
                log.info(f"[{camera_id}] Opened local transcoded preview window (GStreamer PID {viewer.pid})")
            except Exception as e:
                log.warning(f"[{camera_id}] Could not start local preview window: {e}")

    def stop_transcoder(self, camera_id):
        # Stop preview window if active
        viewer = self.active_viewers.get(camera_id)
        if viewer:
            log.info(f"[{camera_id}] Closing local preview window (PID {viewer.pid})...")
            try:
                viewer.terminate()
                viewer.wait(timeout=2)
            except Exception:
                try: viewer.kill()
                except Exception: pass
            self.active_viewers[camera_id] = None

        proc = self.active_processes.get(camera_id)
        if proc:
            log.info(f"[{camera_id}] Stopping FFmpeg dynamic transcoder (PID {proc.pid})...")
            try:
                proc.terminate()
                proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                log.warning(f"[{camera_id}] FFmpeg didn't terminate, killing...")
                proc.kill()
                proc.wait()
            except Exception as e:
                log.error(f"[{camera_id}] Error stopping FFmpeg: {e}")
            self.active_processes[camera_id] = None

    def stop_all(self):
        for camera_id in list(self.active_processes.keys()):
            self.stop_transcoder(camera_id)


# ─── Hikvision ISAPI PTZ ──────────────────────────────────────────────────────
def build_ptz_xml(pan=0, tilt=0, zoom=0):
    """Build Hikvision continuous PTZ XML command."""
    return f"""<?xml version="1.0" encoding="utf-8"?>
<PTZData>
  <pan>{pan}</pan>
  <tilt>{tilt}</tilt>
  <zoom>{zoom}</zoom>
</PTZData>"""


def build_stop_xml():
    return """<?xml version="1.0" encoding="utf-8"?>
<PTZData>
  <pan>0</pan>
  <tilt>0</tilt>
  <zoom>0</zoom>
</PTZData>"""


# Map action → (pan, tilt, zoom) speed values for Hikvision
ACTION_MAP = {
    "pan_left":   (-PTZ_SPEED, 0, 0),
    "pan_right":  ( PTZ_SPEED, 0, 0),
    "tilt_up":    (0,  PTZ_SPEED, 0),
    "tilt_down":  (0, -PTZ_SPEED, 0),
    "zoom_in":    (0, 0,  PTZ_SPEED),
    "zoom_out":   (0, 0, -PTZ_SPEED),
    "pan_stop":   (0, 0, 0),
    "tilt_stop":  (0, 0, 0),
    "zoom_stop":  (0, 0, 0),
    "stop":       (0, 0, 0),
    "home":       None,  # handled separately
}


def execute_ptz(camera_id: str, cmd: dict) -> tuple[bool, str]:
    """Execute a PTZ command via Hikvision ISAPI. Returns (success, error)."""
    cam = CAMERAS.get(camera_id)
    if not cam:
        return False, f"Camera {camera_id} not in local config"

    action  = cmd.get("action", "stop")
    speed   = min(7, max(1, cmd.get("speed", 3)))  # Laravel sends 1-7
    channel = cam["channel"]
    ip      = cam["ip"]
    auth    = HTTPDigestAuth(cam["user"], cam["password"])

    base_url = f"http://{ip}/ISAPI/PTZCtrl/channels/{channel}"

    try:
        if action == "home":
            # Send camera to preset 1 (home position)
            preset_url = f"{base_url}/presets/1/goto"
            r = requests.put(preset_url, auth=auth, timeout=ISAPI_TIMEOUT)
            if r.status_code in (200, 204):
                log.info(f"[{camera_id}] → HOME (preset 1)")
                return True, None
            return False, f"Home failed: HTTP {r.status_code}"

        values = ACTION_MAP.get(action)
        if values is None:
            return False, f"Unknown action: {action}"

        # Scale by speed (1-7) → multiply base speed by speed/4
        factor = speed / 4.0
        pan, tilt, zoom = [int(v * factor) for v in values]
        xml_body = build_ptz_xml(pan, tilt, zoom)

        r = requests.put(
            f"{base_url}/continuous",
            data=xml_body.encode("utf-8"),
            headers={"Content-Type": "application/xml"},
            auth=auth,
            timeout=ISAPI_TIMEOUT,
        )

        if r.status_code in (200, 204):
            direction = action.replace("_", " ").upper()
            log.info(f"[{camera_id}] → {direction} (pan={pan} tilt={tilt} zoom={zoom})")
            return True, None
        else:
            return False, f"ISAPI error: HTTP {r.status_code} — {r.text[:200]}"

    except requests.exceptions.ConnectTimeout:
        return False, f"Camera {ip} unreachable (timeout)"
    except requests.exceptions.ConnectionError:
        return False, f"Camera {ip} not accessible (connection refused)"
    except Exception as e:
        return False, str(e)


# ─── Main loop ───────────────────────────────────────────────────────────────
def main():
    log.info("=" * 60)
    log.info("RoadShield Camera Control & Transcoding Agent")
    log.info(f"  Server : {LARAVEL_URL}")
    log.info(f"  Cameras: {', '.join(CAMERAS.keys())}")
    log.info(f"  Poll   : every {POLL_INTERVAL}s per camera")
    log.info("=" * 60)
    log.info("Waiting for settings & PTZ commands... (Ctrl+C to stop)")

    transcoder_manager = TranscoderManager()

    last_settings_poll = 0.0
    settings_poll_interval = 2.0  # seconds

    try:
        while True:
            # Poll and apply settings
            now = time.time()
            if now - last_settings_poll >= settings_poll_interval:
                last_settings_poll = now
                settings = poll_settings()
                for camera_id, cam_settings in settings.items():
                    if camera_id in CAMERAS:
                        quality = cam_settings.get("quality", "hd")
                        fps     = int(cam_settings.get("fps", 15))
                        transcoder_manager.apply_settings(camera_id, quality, fps)

            # Poll PTZ commands
            for camera_id in CAMERAS:
                commands = poll_commands(camera_id)

                for cmd in commands:
                    cmd_id  = cmd.get("id", "unknown")
                    action  = cmd.get("action", "stop")
                    log.info(f"[{camera_id}] Executing: {action} (id={cmd_id})")

                    success, error = execute_ptz(camera_id, cmd)

                    if success:
                        log.info(f"[{camera_id}] ✅ {action} → OK")
                    else:
                        log.error(f"[{camera_id}] ❌ {action} → {error}")

                    ack_command(camera_id, cmd_id, success, error)

            time.sleep(POLL_INTERVAL)

    except KeyboardInterrupt:
        pass
    finally:
        log.info("Shutting down transcoders...")
        transcoder_manager.stop_all()


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log.info("Stopped.")
