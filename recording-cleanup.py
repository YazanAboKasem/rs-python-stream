#!/usr/bin/env python3
"""
RoadShield — recording-cleanup.py
===================================
Monitors the recordings directory and enforces a maximum total size limit.
When the total size exceeds MAX_SIZE_GB, the oldest recording files are
deleted until the total size drops below TARGET_SIZE_GB (safety margin).

Runs as a background daemon alongside MediaMTX and camera-control.py.

Usage:
  python3 recording-cleanup.py
"""

import os
import time
import logging
import signal
import sys

# ─── Configuration ──────────────────────────────────────────────────────────
RECORDINGS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "recordings")
MAX_SIZE_GB    = 300   # Start deleting when total exceeds this
TARGET_SIZE_GB = 280   # Keep deleting until total drops below this (safety margin)
CHECK_INTERVAL = 60    # Seconds between size checks

# ─── Logging ────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [rec-cleanup] %(levelname)s: %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger(__name__)

# ─── Graceful shutdown ──────────────────────────────────────────────────────
_shutdown = False

def signal_handler(sig, frame):
    global _shutdown
    log.info("Shutdown signal received.")
    _shutdown = True

signal.signal(signal.SIGINT, signal_handler)
signal.signal(signal.SIGTERM, signal_handler)


def get_dir_size_bytes(path: str) -> int:
    """Calculate total size of all files in a directory tree (bytes)."""
    total = 0
    try:
        for dirpath, _dirnames, filenames in os.walk(path):
            for f in filenames:
                fp = os.path.join(dirpath, f)
                try:
                    total += os.path.getsize(fp)
                except OSError:
                    pass
    except OSError:
        pass
    return total


def bytes_to_gb(b: int) -> float:
    """Convert bytes to gigabytes."""
    return b / (1024 ** 3)


def gb_to_bytes(gb: float) -> int:
    """Convert gigabytes to bytes."""
    return int(gb * (1024 ** 3))


def get_all_recording_files(path: str) -> list:
    """
    Get all recording files sorted by modification time (oldest first).
    Returns list of (filepath, size_bytes, mtime) tuples.
    """
    files = []
    try:
        for dirpath, _dirnames, filenames in os.walk(path):
            for f in filenames:
                fp = os.path.join(dirpath, f)
                try:
                    stat = os.stat(fp)
                    files.append((fp, stat.st_size, stat.st_mtime))
                except OSError:
                    pass
    except OSError:
        pass

    # Sort by modification time — oldest first
    files.sort(key=lambda x: x[2])
    return files


def cleanup_recordings():
    """
    Check total recordings size and delete oldest files if over the limit.
    """
    if not os.path.isdir(RECORDINGS_DIR):
        return

    total_bytes = get_dir_size_bytes(RECORDINGS_DIR)
    total_gb = bytes_to_gb(total_bytes)
    max_bytes = gb_to_bytes(MAX_SIZE_GB)
    target_bytes = gb_to_bytes(TARGET_SIZE_GB)

    if total_bytes <= max_bytes:
        log.debug(f"Recordings size: {total_gb:.2f} GB — within limit ({MAX_SIZE_GB} GB)")
        return

    log.warning(
        f"Recordings size {total_gb:.2f} GB exceeds limit of {MAX_SIZE_GB} GB. "
        f"Cleaning up to {TARGET_SIZE_GB} GB..."
    )

    files = get_all_recording_files(RECORDINGS_DIR)
    deleted_count = 0
    deleted_bytes = 0

    for filepath, file_size, mtime in files:
        if total_bytes <= target_bytes:
            break

        try:
            os.remove(filepath)
            total_bytes -= file_size
            deleted_count += 1
            deleted_bytes += file_size
            log.info(f"Deleted: {filepath} ({bytes_to_gb(file_size):.3f} GB)")
        except OSError as e:
            log.error(f"Failed to delete {filepath}: {e}")

    # Clean up empty directories
    _remove_empty_dirs(RECORDINGS_DIR)

    log.info(
        f"Cleanup complete: deleted {deleted_count} files "
        f"({bytes_to_gb(deleted_bytes):.2f} GB freed). "
        f"Current size: {bytes_to_gb(total_bytes):.2f} GB"
    )


def _remove_empty_dirs(path: str):
    """Remove empty subdirectories (but not the root recordings dir)."""
    try:
        for dirpath, dirnames, filenames in os.walk(path, topdown=False):
            if dirpath == path:
                continue
            if not os.listdir(dirpath):
                try:
                    os.rmdir(dirpath)
                    log.debug(f"Removed empty directory: {dirpath}")
                except OSError:
                    pass
    except OSError:
        pass


def main():
    log.info("=" * 60)
    log.info("RoadShield Recording Cleanup Service")
    log.info(f"  Recordings : {RECORDINGS_DIR}")
    log.info(f"  Max size   : {MAX_SIZE_GB} GB")
    log.info(f"  Target size: {TARGET_SIZE_GB} GB")
    log.info(f"  Check every: {CHECK_INTERVAL}s")
    log.info("=" * 60)

    # Ensure recordings directory exists
    os.makedirs(RECORDINGS_DIR, exist_ok=True)

    while not _shutdown:
        try:
            cleanup_recordings()
        except Exception as e:
            log.error(f"Unexpected error during cleanup: {e}")

        # Sleep in small intervals to allow graceful shutdown
        for _ in range(CHECK_INTERVAL):
            if _shutdown:
                break
            time.sleep(1)

    log.info("Recording cleanup service stopped.")


if __name__ == "__main__":
    main()
