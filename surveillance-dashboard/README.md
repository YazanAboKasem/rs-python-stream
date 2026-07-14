# surveillance-dashboard — RoadShield Smart Surveillance

Phase 1 Laravel dashboard. Displays live camera streams from MediaMTX.
**Laravel never relays or processes video bytes** — the browser connects directly to MediaMTX.

---

## Architecture

```
python-stream/ (stream.py)
     ↓  RTSP
MediaMTX  →  HLS  →  Browser (HLS.js)
          →  WebRTC →  Browser (future)
                          ↑
               Laravel passes stream URLs only
```

---

## Quick Start

```bash
# 1. Install dependencies
cd surveillance-dashboard
composer install

# 2. Environment is pre-configured for local dev (.env already present)
#    If starting fresh:
cp .env.example .env
php artisan key:generate

# 3. Start the dashboard
php artisan serve

# 4. Open browser
# http://localhost:8000/surveillance
```

> **Make sure python-stream is running** before opening the dashboard.
> See `../python-stream/README.md` for stream setup.

---

## Configuration

### Changing the stream server

Edit `.env` — **one variable**:

```env
MEDIA_SERVER_HOST=127.0.0.1    # ← change this
```

| Scenario              | Value                        |
|-----------------------|------------------------------|
| Local development     | `127.0.0.1`                 |
| Jetson on LAN         | `192.168.1.x`               |
| Public server         | `your-server-ip`            |
| Domain name           | `stream.yourdomain.com`     |

### Adding cameras

Edit `config/surveillance.php`:

```php
'cameras' => [
    [
        'id'      => 'cam1',
        'label'   => 'Camera 1 — Front View',
        'path'    => 'cam1',    // must match mediamtx.yml path
        'enabled' => true,
    ],
    // Add cam2, cam3... here — no Blade or JS changes needed
],
```

---

## Ports

| Service                 | Port | Who connects |
|-------------------------|------|--------------|
| Laravel dashboard       | 8000 | Browser (HTTP) |
| MediaMTX HLS            | 8888 | Browser (video only) |
| MediaMTX WebRTC         | 8889 | Browser (video only) |
| MediaMTX RTSP           | 8554 | stream.py (publish) |

---

## Folder Structure

```
surveillance-dashboard/
├── app/Http/Controllers/
│   └── SurveillanceController.php   ← passes URLs to view only
├── config/
│   └── surveillance.php             ← camera registry + server config
├── resources/views/
│   ├── layouts/
│   │   └── surveillance.blade.php   ← dark layout shell
│   ├── components/
│   │   └── camera-card.blade.php    ← reusable camera card
│   └── surveillance/
│       └── index.blade.php          ← camera grid page
├── public/
│   ├── css/surveillance.css         ← dark glassmorphism design
│   └── js/stream-player.js          ← HLS.js player (reads data-hls-url)
├── routes/web.php                   ← /surveillance route
├── .env                             ← MEDIA_SERVER_HOST here
└── README.md
```

---

## Deploying to Hostinger cPanel

```bash
# 1. Upload project files via FTP or Git
# 2. Run: composer install --no-dev
# 3. Set .env:
#    MEDIA_SERVER_HOST=stream.yourdomain.com
#    APP_ENV=production
#    APP_DEBUG=false
# 4. php artisan config:cache
# 5. Point cPanel document root to /public
```

---

## Future Phases

| Phase | Feature | Where to add |
|-------|---------|--------------|
| 2 | AI alert events from Python | `SurveillanceController@receiveEvent` |
| 2 | WebRTC low-latency player | `public/js/stream-player.js` (stub ready) |
| 3 | Push notifications | Sidebar alerts panel (placeholder ready) |
| 3 | User authentication | `routes/web.php` middleware |
| 4 | Multi-camera grid | `config/surveillance.php` cameras array |
