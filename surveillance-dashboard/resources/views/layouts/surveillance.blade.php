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

    <div class="sv-layout" id="sv-layout">

        {{-- ── Left Sidebar (collapsible) ───────────────────────── --}}
        <aside class="sv-nav-sidebar" id="sv-nav-sidebar">
            <div class="sv-nav-sidebar-inner">

                {{-- Brand --}}
                <div class="sv-nav-brand">
                    <div class="sv-nav-brand-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <div class="sv-nav-brand-text">
                        <div class="sv-nav-brand-title">RoadShield</div>
                        <div class="sv-nav-brand-sub">Surveillance</div>
                    </div>
                </div>

                {{-- Navigation Links --}}
                <nav class="sv-nav-menu">
                    <div class="sv-nav-section-label">Main</div>

                    <a href="{{ route('surveillance.index') }}"
                       class="sv-nav-link {{ request()->routeIs('surveillance.index') ? 'active' : '' }}"
                       id="nav-monitoring">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        <span>Monitoring Room</span>
                    </a>

                    <a href="{{ route('surveillance.devices') }}"
                       class="sv-nav-link {{ request()->routeIs('surveillance.devices') || request()->routeIs('surveillance.device-settings') ? 'active' : '' }}"
                       id="nav-devices">
                        <i class="bi bi-cpu-fill"></i>
                        <span>Devices</span>
                        @php
                            $deviceCount = count(collect(config('surveillance.devices', []))->where('enabled', true));
                        @endphp
                        <span class="sv-nav-badge">{{ $deviceCount }}</span>
                    </a>

                    <a href="{{ route('surveillance.recordings') }}"
                       class="sv-nav-link {{ request()->routeIs('surveillance.recordings') ? 'active' : '' }}"
                       id="nav-recordings">
                        <i class="bi bi-collection-play-fill"></i>
                        <span>Recordings</span>
                    </a>

                    <div class="sv-nav-section-label" style="margin-top: 20px">System</div>

                    <a href="#" class="sv-nav-link" id="nav-system-info" onclick="toggleSystemPanel(event)">
                        <i class="bi bi-activity"></i>
                        <span>System Info</span>
                    </a>

                    <a href="#" class="sv-nav-link" id="nav-alerts" style="opacity:0.4;pointer-events:none">
                        <i class="bi bi-bell-fill"></i>
                        <span>AI Alerts</span>
                        <span class="sv-nav-badge phase">P2</span>
                    </a>

                    <a href="#" class="sv-nav-link" id="nav-notifications" style="opacity:0.4;pointer-events:none">
                        <i class="bi bi-chat-dots-fill"></i>
                        <span>Notifications</span>
                        <span class="sv-nav-badge phase">P3</span>
                    </a>
                </nav>

                {{-- System Info Panel (collapsible) --}}
                <div class="sv-nav-system-panel" id="sv-system-panel" style="display:none">
                    <div class="sv-nav-system-title">
                        <i class="bi bi-info-circle"></i> System
                    </div>
                    <div class="sv-nav-info-row">
                        <span>Stream server</span>
                        <span>{{ config('surveillance.media_server.host') }}</span>
                    </div>
                    <div class="sv-nav-info-row">
                        <span>HLS port</span>
                        <span>{{ config('surveillance.media_server.hls_port') }}</span>
                    </div>
                    <div class="sv-nav-info-row">
                        <span>Devices</span>
                        <span class="sv-text-green">{{ $deviceCount }}</span>
                    </div>
                </div>

                {{-- Sidebar Footer --}}
                <div class="sv-nav-footer">
                    <span class="sv-nav-footer-text">RoadShield v4.0</span>
                </div>
            </div>
        </aside>

        {{-- ── Main Area ────────────────────────────────────────── --}}
        <div class="sv-main-area" id="sv-main-area">

            {{-- ── Top Bar ──────────────────────────────────────── --}}
            <header class="sv-topbar">
                <div class="sv-topbar-left">
                    <button class="sv-sidebar-toggle" id="sv-sidebar-toggle" onclick="toggleSidebar()" title="Toggle sidebar">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="sv-topbar-page-title">@yield('page-title', 'Monitoring Room')</div>
                </div>

                <div class="sv-topbar-center" id="sv-status-bar" style="display:flex;gap:12px">
                    <div class="sv-status-pill">
                        <span class="sv-status-dot"></span>
                        SYSTEM ONLINE
                    </div>
                    @php
                        $allDevices = collect(config('surveillance.devices', []))->where('enabled', true);
                    @endphp
                    @foreach($allDevices as $dev)
                    <div class="sv-status-pill offline" id="jetson-status-pill-{{ $dev['id'] }}">
                        <span class="sv-status-dot"></span>
                        {{ strtoupper(Str::limit($dev['name'], 12, '')) }}
                    </div>
                    @endforeach
                </div>

                <div class="sv-topbar-right">
                    <span class="sv-clock" id="sv-clock"></span>
                </div>
            </header>

            {{-- ── Main Content ─────────────────────────────────── --}}
            <main class="sv-main">
                @yield('content')
            </main>

        </div>

    </div>

    {{-- Sidebar + Clock scripts --}}
    <script>
        // ── Live clock ──────────────────────────────────────────────
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

        // ── Sidebar toggle ──────────────────────────────────────────
        function toggleSidebar() {
            const layout = document.getElementById('sv-layout');
            layout.classList.toggle('sv-sidebar-collapsed');
            // Save state
            localStorage.setItem('sv-sidebar-collapsed', layout.classList.contains('sv-sidebar-collapsed') ? '1' : '0');
        }

        // Restore sidebar state
        (function() {
            const saved = localStorage.getItem('sv-sidebar-collapsed');
            if (saved === '1') {
                document.getElementById('sv-layout').classList.add('sv-sidebar-collapsed');
            }
        })();

        // ── System panel toggle ─────────────────────────────────────
        function toggleSystemPanel(e) {
            e.preventDefault();
            const panel = document.getElementById('sv-system-panel');
            const link = document.getElementById('nav-system-info');
            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                link.classList.add('active');
            } else {
                panel.style.display = 'none';
                link.classList.remove('active');
            }
        }
    </script>

    @stack('scripts')

</body>

</html>