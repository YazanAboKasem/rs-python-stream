<!doctype html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="surveillance-token" content="{{ config('surveillance.api_token', '') }}">
    <title>@yield('title', 'Surveillance') — RoadShield</title>
    <meta name="description" content="RoadShield Smart Surveillance Dashboard — Live camera monitoring">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap"
        rel="stylesheet">

    {{-- Bootstrap Icons --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    {{-- Surveillance CSS --}}
    <link rel="stylesheet" href="{{ asset('css/surveillance.css') }}?v={{ config('surveillance.asset_version', '1') }}">

    @stack('styles')
</head>

<body>

    <div class="sv-layout">

        {{-- ── Top Bar ────────────────────────────────────────────── --}}
        <header class="sv-topbar">
            <div class="sv-topbar-brand">
                <div class="sv-topbar-icon">
                    <i class="bi bi-shield-check" style="color:#fff"></i>
                </div>
                <div>
                    <div class="sv-topbar-title">RoadShield</div>
                    <div class="sv-topbar-subtitle">Smart Surveillance</div>
                </div>
            </div>

            <div class="sv-topbar-center" id="sv-status-bar" style="display:flex;gap:12px">
                <div class="sv-status-pill">
                    <span class="sv-status-dot"></span>
                    SYSTEM ONLINE
                </div>
                <div class="sv-status-pill offline" id="jetson-status-pill">
                    <span class="sv-status-dot"></span>
                    JETSON OFFLINE
                </div>
            </div>

            <div class="sv-topbar-right">
                <span class="sv-clock" id="sv-clock"></span>
            </div>
        </header>

        {{-- ── Main Content ─────────────────────────────────────── --}}
        <main class="sv-main">
            @yield('content')
        </main>

        {{-- ── Sidebar ──────────────────────────────────────────── --}}
        <aside class="sv-sidebar">
            @yield('sidebar')
        </aside>

    </div>

    {{-- Live clock script --}}
    <script>
        (function () {
            function tick() {
                const el = document.getElementById('sv-clock');
                if (!el) return;
                const n = new Date();
                el.textContent = n.toLocaleTimeString('en-US', { hour12: false }) +
                    ' · ' + n.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
            }
            tick();
            setInterval(tick, 1000);
        })();
    </script>

    @stack('scripts')

</body>

</html>