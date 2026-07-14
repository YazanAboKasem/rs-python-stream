#!/usr/bin/env python3
"""
RoadShield QNAP Background Sync Agent
======================================
A daemon agent running permanently (e.g., inside Docker) on QNAP NAS.
Performs continuous periodic syncs of video recordings from the remote Laravel VPS,
downloads them securely using thread-pool concurrency, and triggers Laravel to clean up
its local storage after verification.
"""

import os
import sys
import time
import json
import logging
import threading
from pathlib import Path
from queue import Queue
from logging.handlers import RotatingFileHandler
from concurrent.futures import ThreadPoolExecutor, as_completed
import requests

# Default configuration path
SCRIPT_DIR = Path(__file__).resolve().parent
CONFIG_PATH = SCRIPT_DIR / "config.json"
LOG_DIR = SCRIPT_DIR / "logs"

class ConfigManager:
    """Manages reading and validation of configuration settings from config.json."""
    def __init__(self, config_path: Path):
        self.config_path = config_path
        self.config = {}
        self.load()

    def load(self):
        if not self.config_path.exists():
            log_error_bootstrap(f"Configuration file not found at {self.config_path}. Please create it from config.json.example.")
            sys.exit(1)
        try:
            with open(self.config_path, "r") as f:
                self.config = json.load(f)
            self.validate()
        except json.JSONDecodeError as e:
            log_error_bootstrap(f"Failed to parse config.json: {str(e)}")
            sys.exit(1)

    def validate(self):
        required = ["server", "token", "download_path"]
        missing = [f for f in required if not self.config.get(f)]
        if missing:
            log_error_bootstrap(f"Missing required config fields: {', '.join(missing)}")
            sys.exit(1)

    @property
    def server(self) -> str:
        return self.config["server"].rstrip("/")

    @property
    def token(self) -> str:
        return self.config["token"]

    @property
    def download_path(self) -> Path:
        return Path(self.config["download_path"])

    @property
    def sync_interval(self) -> int:
        return int(self.config.get("sync_interval_seconds", 60))

    @property
    def max_concurrent_downloads(self) -> int:
        return int(self.config.get("max_concurrent_downloads", 3))

    @property
    def max_retries(self) -> int:
        return int(self.config.get("max_download_retries", 3))

    @property
    def retry_delay(self) -> int:
        return int(self.config.get("retry_delay_seconds", 5))

def log_error_bootstrap(msg: str):
    """Initial fallback print in case logging isn't set up yet."""
    print(f"BOOTSTRAP ERROR: {msg}", file=sys.stderr)

# Setup logging function
def setup_logging(log_dir: Path) -> logging.Logger:
    log_dir.mkdir(parents=True, exist_ok=True)
    log_file = log_dir / "agent.log"
    
    logger = logging.getLogger("qnap-agent")
    logger.setLevel(logging.INFO)
    
    # Clean standard handlers to prevent duplication
    logger.handlers = []

    # Formatters
    formatter = logging.Formatter(
        "%(asctime)s [%(levelname)s] [%(threadName)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S"
    )

    # 1. Console handler
    console = logging.StreamHandler(sys.stdout)
    console.setFormatter(formatter)
    logger.addHandler(console)

    # 2. Rotating file handler (10MB per file, max 5 backups)
    file_handler = RotatingFileHandler(
        log_file, maxBytes=10 * 1024 * 1024, backupCount=5, encoding="utf-8"
    )
    file_handler.setFormatter(formatter)
    logger.addHandler(file_handler)

    return logger

class LaravelClient:
    """Handles REST communications with the remote Laravel API."""
    def __init__(self, server: str, token: str):
        self.server = server
        self.token = token

    @property
    def headers(self) -> dict:
        return {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json"
        }

    def fetch_jetsons(self) -> list:
        url = f"{self.server}/api/surveillance/recordings/browse"
        response = requests.get(url, headers=self.headers, timeout=30)
        response.raise_for_status()
        return response.json().get("jetsons", [])

    def fetch_recordings(self, jetson_name: str) -> list:
        url = f"{self.server}/api/surveillance/recordings/browse/{jetson_name}"
        response = requests.get(url, headers=self.headers, timeout=30)
        response.raise_for_status()
        return response.json().get("recordings", [])

    def download_file_chunk(self, download_url: str, temp_path: Path, expected_size: int):
        with requests.get(download_url, headers=self.headers, stream=True, timeout=60) as r:
            r.raise_for_status()
            with open(temp_path, "wb") as f:
                for chunk in r.iter_content(chunk_size=1024 * 1024): # 1MB
                    if chunk:
                        f.write(chunk)

    def notify_download_complete(self, jetson_name: str, relative_path: str) -> bool:
        url = f"{self.server}/api/surveillance/recordings/download-complete"
        payload = {
            "jetson_name": jetson_name,
            "relative_path": relative_path
        }
        try:
            response = requests.post(url, json=payload, headers=self.headers, timeout=15)
            if response.status_code == 200:
                return True
            log.warning(f"Failed to notify download-complete for {relative_path} (HTTP {response.status_code}): {response.text}")
            return False
        except Exception as e:
            log.error(f"Error notifying download-complete for {relative_path}: {str(e)}")
            return False

# Global singletons placeholder (initialized in main)
log = None
config_manager = None
client = None

class DownloadJob:
    def __init__(self, jetson_name: str, camera_id: str, date_str: str, file_name: str, download_url: str, size: int):
        self.jetson_name = jetson_name
        self.camera_id = camera_id
        self.date_str = date_str
        self.file_name = file_name
        self.download_url = download_url
        self.size = size

    @property
    def relative_path(self) -> str:
        return f"{self.camera_id}/{self.date_str}/{self.file_name}"

    @property
    def unique_id(self) -> str:
        return f"{self.jetson_name}/{self.relative_path}"

class SyncEngine:
    """Manages the lifecycle of scanning files, queuing them, and running concurrent downloaders."""
    def __init__(self):
        self.running = True

    def process_download(self, job: DownloadJob) -> bool:
        """Executes a single download task with built-in retry and verification logic."""
        local_file = config_manager.download_path / job.jetson_name / job.camera_id / job.date_str / job.file_name
        local_file.parent.mkdir(parents=True, exist_ok=True)

        # 1. Skip if already completed and matches size
        if local_file.exists() and local_file.stat().st_size == job.size:
            log.debug(f"File already verified locally: {job.unique_id}")
            # Notify Laravel just in case it wasn't cleaned up on the remote side
            client.notify_download_complete(job.jetson_name, job.relative_path)
            return True

        temp_file = local_file.with_suffix(".tmp")
        max_retries = config_manager.max_retries
        retry_delay = config_manager.retry_delay

        for attempt in range(1, max_retries + 1):
            log.info(f"Attempt {attempt}/{max_retries} - Downloading: {job.unique_id} ({job.size / (1024*1024):.2f} MB)")
            try:
                client.download_file_chunk(job.download_url, temp_file, job.size)
                
                # Check file size integrity
                downloaded_size = temp_file.stat().st_size
                if downloaded_size == job.size:
                    if local_file.exists():
                        local_file.unlink()
                    temp_file.rename(local_file)
                    log.info(f"Downloaded and verified: {job.unique_id}")
                    
                    # Notify Laravel server to delete the VPS copy
                    if client.notify_download_complete(job.jetson_name, job.relative_path):
                        log.info(f"Laravel notified. VPS cleaned up for: {job.unique_id}")
                    return True
                else:
                    log.warning(f"Integrity check failed for {job.unique_id}. Size mismatch (Expected {job.size}, got {downloaded_size})")
                    if temp_file.exists():
                        temp_file.unlink()
            except Exception as e:
                log.error(f"Download failed on attempt {attempt} for {job.unique_id}: {str(e)}")
                if temp_file.exists():
                    temp_file.unlink()
            
            if attempt < max_retries:
                log.info(f"Waiting {retry_delay}s before retrying...")
                time.sleep(retry_delay)

        log.error(f"Failed to sync file after {max_retries} attempts: {job.unique_id}")
        return False

    def run_sync_cycle(self):
        """Scans Laravel VPS for recordings and process them concurrently."""
        log.info("Scanning remote recordings...")
        try:
            jetsons = client.fetch_jetsons()
        except Exception as e:
            log.error(f"Failed to scan remote Jetsons: {str(e)}")
            return

        jobs = []

        # Gather all pending downloads
        for jetson in jetsons:
            jetson_name = jetson.get("name")
            try:
                recordings = client.fetch_recordings(jetson_name)
            except Exception as e:
                log.error(f"Failed to fetch files for jetson {jetson_name}: {str(e)}")
                continue

            for cam_rec in recordings:
                camera_id = cam_rec.get("camera")
                for date_info in cam_rec.get("dates", []):
                    date_str = date_info.get("date")
                    for file_info in date_info.get("files", []):
                        file_name = file_info.get("name")
                        size = file_info.get("size_bytes")
                        download_uri = file_info.get("download_url")
                        download_url = f"{client.server}{download_uri}"

                        job = DownloadJob(
                            jetson_name=jetson_name,
                            camera_id=camera_id,
                            date_str=date_str,
                            file_name=file_name,
                            download_url=download_url,
                            size=size
                        )
                        
                        # Add if local file is missing or has wrong size
                        local_file = config_manager.download_path / job.jetson_name / job.camera_id / job.date_str / job.file_name
                        if not local_file.exists() or local_file.stat().st_size != job.size:
                            jobs.append(job)

        if not jobs:
            log.info("All files synchronized. QNAP is up to date.")
            return

        log.info(f"Sync batch size: {len(jobs)} files pending download.")

        # Process queue concurrently using ThreadPoolExecutor
        workers = config_manager.max_concurrent_downloads
        log.info(f"Starting downloads with {workers} concurrent workers.")
        
        with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="Downloader") as executor:
            future_to_job = {executor.submit(self.process_download, job): job for job in jobs}
            
            success_count = 0
            fail_count = 0
            
            for future in as_completed(future_to_job):
                job = future_to_job[future]
                try:
                    success = future.result()
                    if success:
                        success_count += 1
                    else:
                        fail_count += 1
                except Exception as e:
                    log.error(f"Worker exception processing job {job.unique_id}: {str(e)}")
                    fail_count += 1

            log.info(f"Sync cycle batch complete. Successful: {success_count}, Failed: {fail_count}")

    def start(self):
        log.info("Starting background agent loop.")
        while self.running:
            try:
                self.run_sync_cycle()
            except Exception as e:
                log.critical(f"Unhandled exception in sync cycle: {str(e)}")
            
            log.info(f"Sleeping for {config_manager.sync_interval} seconds before next sync...")
            
            # Sub-second interval sleep checks to allow immediate shutdown
            sleep_timer = 0
            while sleep_timer < config_manager.sync_interval and self.running:
                time.sleep(1)
                sleep_timer += 1

    def stop(self):
        log.info("Stopping QNAP agent...")
        self.running = False

def main():
    global log, config_manager, client
    
    # 1. Initialize configurations
    config_manager = ConfigManager(CONFIG_PATH)

    # 2. Setup persistent rotating loggers
    log = setup_logging(LOG_DIR)
    log.info("=============================================")
    log.info("RoadShield QNAP Background Agent Initializing")
    log.info("=============================================")
    
    log.info(f"Server URL: {config_manager.server}")
    log.info(f"Local Storage Path: {config_manager.download_path}")
    log.info(f"Sync Interval: {config_manager.sync_interval}s")
    log.info(f"Concurrent workers: {config_manager.max_concurrent_downloads}")

    # 3. Setup client
    client = LaravelClient(config_manager.server, config_manager.token)

    # 4. Run loop
    engine = SyncEngine()
    
    # Handle shutdown signals gracefully
    import signal
    def handle_signal(signum, frame):
        log.info(f"Received signal {signum}. Shutting down cleanly...")
        engine.stop()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    engine.start()
    log.info("QNAP Agent has shut down.")

if __name__ == "__main__":
    main()
