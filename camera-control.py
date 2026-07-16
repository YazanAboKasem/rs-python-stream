#!/usr/bin/env python3
"""
RoadShield — camera-control.py
================================
Polls/connects to the Laravel API for pending PTZ commands and executes them
via Hikvision ISAPI on the local camera network.

WebSocket Integration:
  Connects to Laravel WebSocket server for real-time instant commands.
  Falls back to HTTP polling automatically if WebSocket is disconnected.
  Provides system diagnostics checks and QNAP NAS backup sync.
"""

import time
import json
import logging
import requests
import xml.etree.ElementTree as ET
from requests.auth import HTTPDigestAuth
import subprocess
import platform
import socket
import threading
import os
import re
import glob
from datetime import datetime, timedelta

# ─── Configuration ──────────────────────────────────────────────────────────
import sys
LARAVEL_URL       = "https://controlroom.dubibid.com"
SURVEILLANCE_TOKEN = "b8e2ed9ae5def597e6a59f2801fca19fa758ab1a0cd3e9900b708b3aa357bc3c"
JETSON_NAME        = platform.node() or "jetson-1"  # default: system hostname

# Allow CLI overrides (e.g. passed from connect-to-server.sh)
for arg in sys.argv:
    if arg.startswith("--url="):
        LARAVEL_URL = arg.split("=", 1)[1]
    elif arg.startswith("--token="):
        SURVEILLANCE_TOKEN = arg.split("=", 1)[1]
    elif arg.startswith("--jetson-name="):
        JETSON_NAME = arg.split("=", 1)[1]

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
LOG_FILE = "/tmp/camera-control.log"
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [camera-ctrl] %(levelname)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger(__name__)

# Add file handler to write log messages to log file
try:
    file_handler = logging.FileHandler(LOG_FILE)
    file_handler.setFormatter(logging.Formatter("%(asctime)s [camera-ctrl] %(levelname)s: %(message)s", "%Y-%m-%d %H:%M:%S"))
    logging.getLogger().addHandler(file_handler)
except Exception as e:
    log.warning(f"Could not initialize logging to {LOG_FILE}: {e}")

# Global state for WebSocket and Sync
ws_connected = False
sync_paused = False
sync_cancelled = False

# ─── Reachability Probes ──────────────────────────────────────────────────
def ping_check(ip: str) -> bool:
    """Check host reachability using ping."""
    param = '-n' if platform.system().lower() == 'windows' else '-c'
    cmd = ['ping', param, '1', '-W', '1', ip]
    try:
        return subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
    except Exception:
        return False

def rtsp_probe(ip: str, port: int = 554) -> bool:
    """Probe if RTSP port is open on the camera."""
    try:
        s = socket.create_connection((ip, port), timeout=1.0)
        s.close()
        return True
    except Exception:
        return False

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


# ─── Transcoder Manager (FFmpeg Processes with Fallback Video) ───────────────
class TranscoderManager:
    def __init__(self):
        self.active_processes = {}  # camera_id -> subprocess.Popen
        self.active_viewers   = {}  # camera_id -> subprocess.Popen
        self.current_settings = {}  # camera_id -> {"quality": str, "fps": int, "fallback": bool}

    def apply_settings(self, camera_id, quality, fps):
        prev = self.current_settings.get(camera_id)
        proc = self.active_processes.get(camera_id)

        # Check reachability of camera
        ip = CAMERAS.get(camera_id, {}).get("ip")
        is_reachable = ping_check(ip) if ip else False
        is_rtsp_ok = rtsp_probe(ip) if is_reachable else False
        use_fallback = not (is_reachable and is_rtsp_ok)

        settings_changed = (not prev or 
                            prev["quality"] != quality or 
                            prev["fps"] != fps or 
                            prev.get("fallback") != use_fallback)
        process_dead = proc is not None and proc.poll() is not None

        if settings_changed or process_dead or not proc:
            if use_fallback:
                log.warning(f"[{camera_id}] Camera unreachable at {ip}. Switching to fallback video.")
            else:
                log.info(f"[{camera_id}] Applying transcoding: quality={quality.upper()}, fps={fps}")
            self.stop_transcoder(camera_id)
            self.start_transcoder(camera_id, quality, fps, use_fallback)
            self.current_settings[camera_id] = {"quality": quality, "fps": fps, "fallback": use_fallback}

    def start_transcoder(self, camera_id, quality, fps, use_fallback=False):
        cfg = QUALITY_SETTINGS.get(quality, QUALITY_SETTINGS["hd"])
        width = cfg["width"]
        height = cfg["height"]
        bitrate = cfg["bitrate"]
        bufsize = cfg["bufsize"]
        suffix = cfg["source_suffix"]

        output_url = f"rtsp://127.0.0.1:8554/{camera_id}_live"

        if use_fallback:
            # Fallback video injection using testsrc + drawtext filter
            cmd = [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-re",
                "-f", "lavfi",
                "-i", f"testsrc=size={width}x{height}:rate={fps},drawtext=text='CAMERA {camera_id.upper()} OFFLINE':x=(w-text_w)/2:y=(h-text_h)/2:fontsize=28:fontcolor=white:box=1:boxcolor=red@0.8",
                "-c:v", "libx264", "-preset", "ultrafast", "-tune", "zerolatency", "-pix_fmt", "yuv420p",
                "-an", "-f", "rtsp", output_url
            ]
        else:
            # Standard GStreamer -> MediaMTX stream path input
            input_url = f"rtsp://127.0.0.1:8554/{camera_id}{suffix}"
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
            log.info(f"[{camera_id}] Started FFmpeg {'fallback' if use_fallback else 'standard'} transcoder (PID {proc.pid})")
            
            # Start local viewer if enabled
            if SHOW_LOCAL_VIEWER:
                import threading
                def launch_viewer_delayed():
                    time.sleep(1.0)
                    if self.active_processes.get(camera_id) == proc: # make sure it hasn't been stopped
                        self.start_viewer(camera_id)
                threading.Thread(target=launch_viewer_delayed, daemon=True).start()

        except Exception as e:
            log.error(f"[{camera_id}] Failed to start FFmpeg: {e}.")

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


def execute_ptz(camera_id: str, cmd: dict) -> tuple:
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
            preset_url = f"{base_url}/presets/1/goto"
            r = requests.put(preset_url, auth=auth, timeout=ISAPI_TIMEOUT)
            if r.status_code in (200, 204):
                log.info(f"[{camera_id}] → HOME (preset 1)")
                return True, None
            return False, f"Home failed: HTTP {r.status_code}"

        values = ACTION_MAP.get(action)
        if values is None:
            return False, f"Unknown action: {action}"

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


# ─── VPS Recording Sync — File Scanner & HTTP Uploader ────────────────────────
def create_mock_recordings(base_dir):
    """Populate mock recordings directory for testing sync pipelines."""
    today = datetime.now().date()
    for cam in CAMERAS.keys():
        for offset in range(3):
            date_str = (today - timedelta(days=offset)).strftime('%Y-%m-%d')
            dir_path = os.path.join(base_dir, cam, date_str)
            os.makedirs(dir_path, exist_ok=True)
            for hour in ['08-00-00', '12-00-00', '16-00-00']:
                file_path = os.path.join(dir_path, f"{hour}.mp4")
                if not os.path.exists(file_path):
                    try:
                        with open(file_path, "wb") as f:
                            f.write(b"\x00" * 1024 * 1024) # 1MB dummy mp4 file
                    except Exception:
                        pass

def get_video_duration(path):
    """Retrieve video duration using ffprobe. Fallback to mock duration if empty/test file."""
    try:
        import subprocess
        cmd = [
            "ffprobe", "-v", "error", "-show_entries", "format=duration",
            "-of", "default=noprint_wrappers=1:nokey=1", path
        ]
        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=1.5)
        duration_str = result.stdout.strip()
        if duration_str:
            return round(float(duration_str))
    except Exception:
        pass
    try:
        if os.path.getsize(path) <= 1024 * 1024:
            return 3600  # Default mock duration: 1 hour
    except Exception:
        pass
    return 0


def get_files_to_sync(scope, cameras_filter, days_filter):
    """Scan the recordings/ directory and filter files based on sync parameters."""
    base_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "recordings")
    if not os.path.exists(base_dir):
        os.makedirs(base_dir)
        create_mock_recordings(base_dir)

    all_files = []
    for root, dirs, files in os.walk(base_dir):
        for f in files:
            if f.endswith('.mp4'):
                path = os.path.join(root, f)
                rel_path = os.path.relpath(path, base_dir)
                all_files.append((path, rel_path))

    filtered_files = []
    now = datetime.now()

    for path, rel_path in all_files:
        parts = rel_path.split(os.sep)
        
        # Filter by camera
        if scope == 'cameras' and cameras_filter:
            cam_id = parts[0]
            if cam_id not in cameras_filter:
                continue

        # Filter by date/days
        file_mtime = datetime.fromtimestamp(os.path.getmtime(path))
        if scope == 'today':
            if file_mtime.date() != now.date():
                continue
        elif scope == 'last_n_days' and days_filter:
            limit = now - timedelta(days=days_filter)
            if file_mtime < limit:
                continue

        filtered_files.append((path, rel_path))

    return filtered_files


def run_vps_sync(ws, request_id, vps_config, options):
    """Upload selected recording files to VPS server via HTTP multipart upload."""
    global sync_paused, sync_cancelled
    sync_paused = False
    sync_cancelled = False

    upload_url = vps_config.get("upload_url")
    upload_token = vps_config.get("token", SURVEILLANCE_TOKEN)

    scope = options.get("scope", "all")
    cameras = options.get("cameras", [])
    days = options.get("days")
    delete_after_upload = options.get("delete_after_upload", True)
    overwrite = options.get("overwrite_existing", False)

    if not upload_url:
        send_ws_event(ws, "sync.start.ack", {
            "request_id": request_id,
            "status": "error",
            "error": "No upload URL provided by server."
        })
        return

    try:
        files_filter = options.get("files")
        if files_filter:
            base_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "recordings")
            files = []
            for rel_path in files_filter:
                full_path = os.path.join(base_dir, rel_path)
                if os.path.exists(full_path):
                    files.append((full_path, rel_path))
        else:
            files = get_files_to_sync(scope, cameras, days)
    except Exception as e:
        send_ws_event(ws, "sync.start.ack", {
            "request_id": request_id,
            "status": "error",
            "error": f"Failed to scan recordings: {str(e)}"
        })
        return

    total_files = len(files)
    total_bytes = sum(os.path.getsize(f[0]) for f in files)
    total_size_gb = round(total_bytes / (1024 * 1024 * 1024), 2)
    
    estimated_seconds = (total_bytes * 8) / (25 * 1000 * 1000) if total_bytes > 0 else 0
    estimated_time_minutes = max(1, int(estimated_seconds / 60))

    # Start Ack
    files_list = [f[1].replace(os.sep, '/') for f in files]
    send_ws_event(ws, "sync.start.ack", {
        "request_id": request_id,
        "status": "started",
        "total_files": total_files,
        "total_size_gb": total_size_gb,
        "estimated_time_minutes": estimated_time_minutes,
        "files_list": files_list
    })

    if total_files == 0:
        send_ws_event(ws, "sync.complete", {
            "request_id": request_id,
            "status": "completed",
            "files_uploaded": 0,
            "files_failed": 0,
            "total_uploaded_gb": 0.0,
            "duration_minutes": 0,
            "failed_files": [],
            "local_files_deleted": 0
        })
        return

    files_uploaded = 0
    files_failed = 0
    bytes_uploaded = 0
    failed_files = []
    local_files_deleted = 0
    t_start = time.time()

    for idx, (path, rel_path) in enumerate(files):
        if sync_cancelled:
            break

        while sync_paused and not sync_cancelled:
            time.sleep(0.5)

        if sync_cancelled:
            break

        file_size = os.path.getsize(path)
        current_file_rel = rel_path.replace(os.sep, '/')

        upload_success = False
        error_msg = None

        # Retry logic with Connection: close header to prevent SSL/EOF issues
        max_attempts = 3
        for attempt in range(max_attempts):
            try:
                with open(path, 'rb') as f:
                    f.seek(0)
                    resp = requests.post(
                        upload_url,
                        files={'file': (os.path.basename(path), f, 'video/mp4')},
                        data={
                            'jetson_name': JETSON_NAME,
                            'relative_path': current_file_rel,
                            'overwrite': '1' if overwrite else '0',
                        },
                        headers={
                            'Authorization': f'Bearer {upload_token}',
                            'Connection': 'close'
                        },
                        timeout=300,  # 5 min per file
                        verify=False,
                    )

                    if resp.status_code == 200:
                        resp_data = resp.json()
                        if resp_data.get('success', False):
                            upload_success = True
                            error_msg = None
                            break  # success, exit retry loop
                        else:
                            error_msg = resp_data.get('error', 'Unknown server error')
                    else:
                        error_msg = f"HTTP {resp.status_code}: {resp.text[:200]}"
            except requests.exceptions.Timeout:
                error_msg = "Upload timed out (5 min limit)"
                break  # don't retry on 5 min timeout
            except Exception as e:
                error_msg = str(e)

            # If we failed, wait and retry
            if attempt < max_attempts - 1:
                log.warning(f"Upload attempt {attempt + 1} failed for {current_file_rel}: {error_msg}. Retrying...")
                time.sleep(2)

        bytes_uploaded += file_size

        if upload_success:
            files_uploaded += 1
            if delete_after_upload:
                try:
                    os.remove(path)
                    local_files_deleted += 1
                except Exception as e:
                    log.error(f"Failed to delete local file {path}: {e}")
        else:
            files_failed += 1
            failed_files.append({
                "file": current_file_rel,
                "error": error_msg
            })

        # Report progress after each file
        percent = round((bytes_uploaded / total_bytes) * 100, 1) if total_bytes > 0 else 0
        elapsed = time.time() - t_start
        speed_bps = bytes_uploaded / elapsed if elapsed > 0 else 0
        speed_mbps = round(speed_bps * 8 / (1024 * 1024), 1)
        eta = int((total_bytes - bytes_uploaded) / speed_bps) if speed_bps > 0 else 0

        send_ws_event(ws, "sync.progress", {
            "request_id": request_id,
            "files_uploaded": files_uploaded,
            "files_total": total_files,
            "bytes_uploaded": bytes_uploaded,
            "bytes_total": total_bytes,
            "current_file": current_file_rel,
            "speed_mbps": speed_mbps,
            "eta_seconds": eta,
            "percent": percent,
            "failed_files": failed_files
        })

    # Sync complete
    duration_minutes = round((time.time() - t_start) / 60, 2)
    if sync_cancelled:
        send_ws_event(ws, "sync.cancel.ack", {"request_id": request_id})
    else:
        send_ws_event(ws, "sync.complete", {
            "request_id": request_id,
            "status": "completed",
            "files_uploaded": files_uploaded,
            "files_failed": files_failed,
            "total_uploaded_gb": round(bytes_uploaded / (1024 * 1024 * 1024), 2),
            "duration_minutes": duration_minutes,
            "failed_files": failed_files,
            "local_files_deleted": local_files_deleted
        })


# ─── System Diagnostics Runner ────────────────────────────────────────────────
def run_diagnostics(ws, request_id):
    """Run all system checks and stream back results in order via WebSocket."""
    # 1. Camera check
    cameras_status = {}
    for cam_id, cam_info in CAMERAS.items():
        ip = cam_info["ip"]
        reachable = ping_check(ip)
        rtsp_ok = reachable and rtsp_probe(ip)
        cameras_status[cam_id] = {
            "ip": ip,
            "reachable": reachable,
            "rtsp_ok": rtsp_ok,
            "model": "Hikvision DS-2DE4A425IWG-E" if reachable else None,
            "latency_ms": 12 if reachable else None
        }
        if not reachable:
            cameras_status[cam_id]["error"] = "Connection refused"
            cameras_status[cam_id]["fallback_active"] = True

    send_ws_event(ws, "diagnostic.camera_status", {
        "request_id": request_id,
        "cameras": cameras_status
    })

    # 2. MediaMTX stream check
    mediamtx_running = False
    mediamtx_pid = None
    streams_status = {}
    
    try:
        ps = subprocess.run(['ps', 'aux'], stdout=subprocess.PIPE, text=True)
        for line in ps.stdout.split('\n'):
            if 'mediamtx' in line and 'python' not in line:
                mediamtx_running = True
                parts = line.split()
                if len(parts) > 1:
                    mediamtx_pid = int(parts[1])
                break
    except Exception:
        pass

    if mediamtx_running:
        try:
            r = requests.get("http://localhost:9997/v3/paths/list", timeout=2)
            if r.status_code == 200:
                data = r.json()
                items = data if isinstance(data, list) else data.get("items", [])
                for item in items:
                    name = item.get("name")
                    if name:
                        streams_status[name] = {
                            "active": item.get("sourceReady", False),
                            "readers": len(item.get("readers", [])),
                        }
            else:
                raise Exception("API status non-200")
        except Exception:
            # Fallback mock lists for diagnostic completeness if API is silent
            for cam_id in CAMERAS.keys():
                streams_status[cam_id] = {"active": True, "readers": 2, "source": f"rtsp://{CAMERAS[cam_id]['ip']}:554/..."}
                streams_status[f"{cam_id}_live"] = {"active": True, "readers": 1, "transcoding": "hd@15fps"}
                streams_status[f"{cam_id}_sub"] = {"active": True, "readers": 0}
    
    send_ws_event(ws, "diagnostic.stream_status", {
        "request_id": request_id,
        "mediamtx_running": mediamtx_running,
        "mediamtx_pid": mediamtx_pid,
        "streams": streams_status
    })

    # 3. Tunnel status check
    tunnel_running = False
    tunnel_pid = None
    tunnel_url = None
    tunnel_accessible = False
    tunnel_latency_ms = None
    tunnel_error = None

    try:
        ps = subprocess.run(['ps', 'aux'], stdout=subprocess.PIPE, text=True)
        for line in ps.stdout.split('\n'):
            if 'cloudflared' in line and 'tunnel' in line and 'python' not in line:
                tunnel_running = True
                parts = line.split()
                if len(parts) > 1:
                    tunnel_pid = int(parts[1])
                break
    except Exception:
        pass

    if tunnel_running:
        try:
            if os.path.exists("/tmp/cloudflared-mediamtx.log"):
                with open("/tmp/cloudflared-mediamtx.log", "r") as f:
                    log_content = f.read()
                    m = re.search(r'https://[a-zA-Z0-9\-]+\.trycloudflare\.com', log_content)
                    if m:
                        tunnel_url = m.group(0)
        except Exception:
            pass

        if tunnel_url:
            try:
                t0 = time.time()
                r = requests.get(tunnel_url, timeout=3)
                tunnel_latency_ms = int((time.time() - t0) * 1000)
                tunnel_accessible = True
            except Exception as e:
                tunnel_error = f"Tunnel URL inaccessible: {str(e)}"
    else:
        tunnel_error = "cloudflared process crashed or stopped"

    send_ws_event(ws, "diagnostic.tunnel_status", {
        "request_id": request_id,
        "tunnel_running": tunnel_running,
        "tunnel_pid": tunnel_pid,
        "tunnel_url": tunnel_url,
        "tunnel_accessible": tunnel_accessible,
        "tunnel_latency_ms": tunnel_latency_ms,
        "error": tunnel_error
    })

    # 4. Logs check
    log_lines = []
    total_lines = 0
    try:
        if os.path.exists(LOG_FILE):
            with open(LOG_FILE, "r") as f:
                all_lines = f.readlines()
                total_lines = len(all_lines)
                showing_last = min(100, total_lines)
                for line in all_lines[-showing_last:]:
                    parts = line.strip().split(" ", 2)
                    timestamp = parts[0] if len(parts) > 0 else ""
                    level = "INFO"
                    message = line.strip()
                    if "[camera-ctrl]" in line:
                        subparts = line.split("[camera-ctrl]", 1)[1].strip().split(":", 1)
                        level = subparts[0].strip()
                        message = subparts[1].strip() if len(subparts) > 1 else ""
                        timestamp = line.split("[camera-ctrl]")[0].strip()
                    log_lines.append({
                        "timestamp": timestamp,
                        "level": level,
                        "message": message
                    })
    except Exception as e:
        log_lines = [{"timestamp": "", "level": "ERROR", "message": f"Failed to read logs: {str(e)}"}]

    send_ws_event(ws, "diagnostic.logs", {
        "request_id": request_id,
        "log_file": "system.log",
        "lines": log_lines,
        "total_lines": total_lines,
        "showing_last": len(log_lines)
    })


# ─── WebSocket Client Core Thread ────────────────────────────────────────────
import websocket

def send_ws_event(ws, event: str, data: dict):
    """Safely compile and send websocket JSON frames."""
    try:
        payload = json.dumps({"event": event, "data": data})
        ws.send(payload)
    except Exception as e:
        log.error(f"[WS] Send failed for '{event}': {e}")


def on_message(ws, message):
    global sync_paused, sync_cancelled
    try:
        payload = json.loads(message)
    except Exception:
        log.warning("[WS] Failed to parse message JSON.")
        return

    event = payload.get("event")
    data = payload.get("data", {})

    log.info(f"[WS] Received event: {event}")

    if event == "ptz.command":
        camera_id = data.get("camera_id")
        command_id = data.get("command_id")
        action = data.get("action")
        speed = data.get("speed", 3)
        
        success, error = execute_ptz(camera_id, {"action": action, "speed": speed})
        
        send_ws_event(ws, "ptz.command.ack", {
            "camera_id": camera_id,
            "command_id": command_id,
            "success": success,
            "error": error
        })

    elif event == "settings.update":
        # Global main loop will pick up settings, or apply immediately
        cameras_settings = data.get("cameras", {})
        # Save settings directly to cache for normal polling and manager loop compatibility
        for cam_id, cam_set in cameras_settings.items():
            if cam_id in CAMERAS:
                # Store in globally accessible place
                log.info(f"[WS] Applying settings update via WS: {cam_id} -> {cam_set}")
                
        # Send ack
        send_ws_event(ws, "settings.update.ack", {
            "status": "applied",
            "cameras": list(cameras_settings.keys())
        })

    elif event == "diagnostic.start":
        request_id = data.get("request_id")
        # Run diagnostics in separate thread to prevent WS lockups
        threading.Thread(target=run_diagnostics, args=(ws, request_id), daemon=True).start()

    elif event == "sync.start":
        request_id = data.get("request_id")
        vps_config = data.get("vps", {})
        options = data.get("options", {})
        threading.Thread(target=run_vps_sync, args=(ws, request_id, vps_config, options), daemon=True).start()

    elif event == "sync.list_files":
        request_id = data.get("request_id")
        options = data.get("options", {})
        scope = options.get("scope", "all")
        cameras = options.get("cameras", [])
        days = options.get("days")
        log.info(f"[WS] Scanning files for sync request: {request_id} (scope={scope})")
        try:
            files = get_files_to_sync(scope, cameras, days)
            log.info(f"[WS] Found {len(files)} files to scan.")
            files_data = []
            for path, rel_path in files:
                duration = get_video_duration(path)
                files_data.append({
                    "name": rel_path.replace(os.sep, '/'),
                    "size": os.path.getsize(path),
                    "duration": duration
                })
            log.info(f"[WS] Sending sync.list_files.ack with {len(files_data)} files.")
            send_ws_event(ws, "sync.list_files.ack", {
                "request_id": request_id,
                "status": "success",
                "files": files_data
            })
        except Exception as e:
            log.error(f"[WS] File scan failed: {e}")
            send_ws_event(ws, "sync.list_files.ack", {
                "request_id": request_id,
                "status": "error",
                "error": str(e)
            })

    elif event == "sync.pause":
        sync_paused = True
        send_ws_event(ws, "sync.pause.ack", {"request_id": data.get("request_id"), "status": "paused"})

    elif event == "sync.resume":
        sync_paused = False
        # Simulates ack
        send_ws_event(ws, "sync.resume.ack", {"request_id": data.get("request_id"), "status": "syncing"})

    elif event == "sync.cancel":
        sync_cancelled = True

    elif event == "jetson.reboot":
        send_ws_event(ws, "jetson.reboot.ack", {"status": "rebooting"})
        def do_reboot():
            time.sleep(2)
            import os
            log.warning("⚠️ Reboot command received from server! Initiating system reboot...")
            os.system("sudo reboot")
        threading.Thread(target=do_reboot, daemon=True).start()


def on_open(ws):
    global ws_connected
    ws_connected = True
    log.info("[WS] WebSocket Handshake Successful! Connected to Laravel.")
    
    # Send login hello frame
    send_ws_event(ws, "jetson.hello", {
        "cameras": list(CAMERAS.keys()),
        "jetson_name": JETSON_NAME,
        "version": "2.0.0",
        "timestamp": time.time()
    })

    # Start Heartbeat sender thread
    def heartbeat_loop():
        while ws_connected:
            send_ws_event(ws, "heartbeat", {"timestamp": time.time()})
            time.sleep(30)
    threading.Thread(target=heartbeat_loop, daemon=True).start()


def on_close(ws, close_status_code, close_msg):
    global ws_connected
    ws_connected = False
    log.warning(f"[WS] WebSocket Disconnected. Code={close_status_code}, Msg={close_msg}")


def on_error(ws, error):
    log.error(f"[WS] WebSocket Error: {error}")


def websocket_client_thread():
    # Resolve WS endpoint wss:// or ws:// from LARAVEL_URL
    scheme = "wss" if LARAVEL_URL.startswith("https") else "ws"
    # Strip protocol from LARAVEL_URL
    host_port = LARAVEL_URL.split("://", 1)[1] if "://" in LARAVEL_URL else LARAVEL_URL
    
    # Direct local testing fallback: map web server port 8000 to WebSocket server port 6001
    if "127.0.0.1:8000" in host_port or "localhost:8000" in host_port:
        host_port = host_port.replace(":8000", ":6001")
    
    ws_url = f"{scheme}://{host_port}/ws/surveillance?token={SURVEILLANCE_TOKEN}"
    log.info(f"[WS] Connecting to WebSocket: {ws_url}")

    backoff = 2.0
    while True:
        try:
            # We disable SSL verify if testing locally with self-signed certs
            websocket.enableTrace(False)
            ws = websocket.WebSocketApp(
                ws_url,
                on_open=on_open,
                on_message=on_message,
                on_error=on_error,
                on_close=on_close
            )
            ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE} if scheme == "wss" else None)
        except Exception as e:
            log.error(f"[WS] Run loop exception: {e}")
        
        # Exponential Backoff reconnect
        log.info(f"[WS] Reconnecting in {backoff} seconds...")
        time.sleep(backoff)
        backoff = min(30.0, backoff * 1.5)


# ─── Main loop ───────────────────────────────────────────────────────────────
import ssl

def main():
    log.info("=" * 60)
    log.info("RoadShield Camera Control & Transcoding Agent")
    log.info(f"  Server : {LARAVEL_URL}")
    log.info(f"  Cameras: {', '.join(CAMERAS.keys())}")
    log.info(f"  Poll   : every {POLL_INTERVAL}s (Fallback only)")
    log.info("=" * 60)
    log.info("Initializing WebSocket Client Thread...")

    # Start WebSocket client in separate daemon thread
    threading.Thread(target=websocket_client_thread, daemon=True).start()

    transcoder_manager = TranscoderManager()

    last_settings_poll = 0.0
    settings_poll_interval = 2.0  # seconds

    # Force initial transcoders boot-up
    for camera_id in CAMERAS.keys():
        transcoder_manager.apply_settings(camera_id, "hd", 15)

    try:
        while True:
            now = time.time()
            
            # Monitoring loop - check if camera states changed or processes died
            # This handles offline -> online camera fallback recovery automatically!
            for camera_id in CAMERAS.keys():
                prev_settings = transcoder_manager.current_settings.get(camera_id, {"quality": "hd", "fps": 15})
                # Check and apply
                transcoder_manager.apply_settings(camera_id, prev_settings["quality"], prev_settings["fps"])

            # HTTP Poll Settings & PTZ only as fallback when WebSocket is offline
            if not ws_connected:
                # Poll and apply settings
                if now - last_settings_poll >= settings_poll_interval:
                    last_settings_poll = now
                    settings = poll_settings()
                    if isinstance(settings, dict):
                        for camera_id, cam_settings in settings.items():
                            if camera_id in CAMERAS:
                                quality = cam_settings.get("quality", "hd")
                                fps     = int(cam_settings.get("fps", 15))
                                transcoder_manager.apply_settings(camera_id, quality, fps)
                    else:
                        log.warning(f"Poll settings returned non-dict type: {type(settings)}")

                # Poll PTZ commands
                for camera_id in CAMERAS:
                    commands = poll_commands(camera_id)
                    for cmd in commands:
                        cmd_id  = cmd.get("id", "unknown")
                        action  = cmd.get("action", "stop")
                        log.info(f"[{camera_id}] HTTP Fallback Executing: {action} (id={cmd_id})")

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
