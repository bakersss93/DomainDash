<!DOCTYPE html>
<html lang="en" class="{{ (auth()->user()->dark_mode ?? false) ? 'dark' : '' }}"
      xmlns:x-on="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name','DomainDash') }}</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <style>
        :root {
            --primary: {{ data_get(\App\Models\Setting::get('branding'), 'primary', '#1f2937') }};
            --accent:  {{ data_get(\App\Models\Setting::get('branding'), 'accent', '#06b6d4') }};
            --bg:      {{ data_get(\App\Models\Setting::get('branding'), 'bg', '#ffffff') }};
            --text:    {{ data_get(\App\Models\Setting::get('branding'), 'text', '#111827') }};
            --surface-muted: color-mix(in srgb, var(--bg) 84%, #dbe3ee 16%);
            --surface-elevated: color-mix(in srgb, var(--bg) 92%, #ffffff 8%);
            --border-subtle: color-mix(in srgb, var(--text) 12%, #dbe3ee 88%);
            --text-muted: color-mix(in srgb, var(--text) 62%, #94a3b8 38%);
            --sidebar-bg: color-mix(in srgb, var(--primary) 90%, #111827 10%);
            --sidebar-bg-soft: color-mix(in srgb, var(--primary) 78%, #1e293b 22%);
            --sidebar-border: rgba(255, 255, 255, 0.14);
            --nav-hover: rgba(255, 255, 255, 0.12);
            --nav-active: var(--accent);
            --accent-contrast: color-mix(in srgb, var(--accent) 6%, #ffffff 94%);
            --success-bg: #dcfce7;
            --success-border: #86efac;
            --success-text: #14532d;
            --warning-bg: #fef3c7;
            --warning-border: #fcd34d;
            --warning-text: #78350f;
            --danger-text: #dc2626;
        }

        /* Dark mode: invert background & text colours */
        html.dark {
            --bg: #111827;
            --text: #f9fafb;
            --surface-muted: #1f2937;
            --surface-elevated: #0f172a;
            --border-subtle: #334155;
            --text-muted: #94a3b8;
            --sidebar-bg: #0b1220;
            --sidebar-bg-soft: #111c31;
            --sidebar-border: rgba(148, 163, 184, 0.2);
            --nav-hover: rgba(148, 163, 184, 0.14);
            --nav-active: #06b6d4;
            --accent-contrast: #ecfeff;
            --success-bg: rgba(16, 185, 129, 0.2);
            --success-border: rgba(16, 185, 129, 0.55);
            --success-text: #d1fae5;
            --warning-bg: rgba(245, 158, 11, 0.2);
            --warning-border: rgba(245, 158, 11, 0.5);
            --warning-text: #fde68a;
            --danger-text: #f87171;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: "Figtree", "Segoe UI", sans-serif;
        }

        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            color: #fff;
            min-height: 100vh;
            overflow-y: auto;
            border-right: 1px solid var(--sidebar-border);
        }

        .sidebar nav {
            padding: 8px;
        }

        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .nav-item {
            margin-bottom: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s;
            font-size: 14px;
            cursor: pointer;
        }

        .nav-link:hover {
            background: var(--nav-hover);
        }

        .nav-link.active {
            background: var(--nav-active);
            color: var(--accent-contrast);
        }

        .nav-link .icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .nav-link .text {
            flex: 1;
        }

        .nav-link .arrow {
            font-size: 12px;
            transition: transform 0.2s;
        }

        .nav-section.expanded .nav-toggle .arrow {
            transform: rotate(90deg);
        }

        .nav-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            padding-left: 32px;
        }

        .nav-section.expanded .nav-submenu {
            max-height: 500px;
        }

        .nav-submenu .nav-link {
            padding: 8px 14px;
            font-size: 13px;
        }

        .topbar {
            height: 56px;
            background: var(--bg);
            color: var(--text);
            border-bottom: 1px solid var(--border-subtle);
            display:flex;
            align-items:center;
            padding:0 16px;
        }

        .topbar-left {
            display:flex;
            align-items:center;
        }

        .topbar-right {
            margin-left:auto;
            display:flex;
            align-items:center;
            gap:16px;
        }

        .logo { max-height: 48px; }

        .notif {
            position: relative;
            cursor: pointer;
        }

        .notif .panel {
            position: absolute;
            right:0;
            top: 36px;
            width: 380px;
            background: var(--bg);
            color: var(--text);
            border:1px solid var(--border-subtle);
            box-shadow:0 10px 20px rgba(0,0,0,.08);
            display:none;
            z-index:40;
        }

        .notif.open .panel { display:block; }

        .danger { color: var(--danger-text); }

        .badge-red {
            color: #fff;
            background: var(--danger-text);
            border-radius: 9999px;
            padding: 0 8px;
            font-size: 12px;
            margin-left:4px;
        }

        .dd-flash-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 14px;
            border: 1px solid transparent;
            font-weight: 500;
        }

        .dd-flash-banner.is-status {
            background: var(--success-bg);
            border-color: var(--success-border);
            color: var(--success-text);
        }

        .dd-flash-banner.is-impersonating {
            background: var(--warning-bg);
            border-color: var(--warning-border);
            color: var(--warning-text);
        }

        .dd-flash-action {
            border: 1px solid currentColor;
            background: rgba(255, 255, 255, 0.55);
            color: inherit;
            border-radius: 9999px;
            padding: 7px 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        html.dark .dd-flash-action {
            background: rgba(15, 23, 42, 0.65);
            color: inherit;
        }

        /* Accent button + spinner */
        .btn-accent {
            background: linear-gradient(145deg, var(--accent), color-mix(in srgb, var(--accent) 72%, #0f172a 28%));
            color: var(--accent-contrast);
            border: 1px solid transparent;
            padding: 9px 16px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        .btn-accent:hover {
            filter: brightness(1.05);
        }
        .btn-accent[disabled] {
            opacity: .6;
            cursor: default;
        }
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 999px;
            border: 2px solid rgba(0,0,0,.1);
            border-top-color: var(--accent);
            animation: spin 0.8s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* User dropdown styling */
        .user-menu details {
            position: relative;
        }
        .user-menu summary {
            list-style: none;
            cursor: pointer;
            display:flex;
            align-items:center;
            gap:8px;
            padding:6px 10px;
            border-radius:9999px;
            background:var(--surface-muted);
        }
        html.dark .user-menu summary {
            background:var(--surface-muted);
            color:var(--text);
        }
        .user-menu summary::-webkit-details-marker {
            display:none;
        }
        .user-avatar-circle {
            width:24px;
            height:24px;
            border-radius:9999px;
            background:var(--accent);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:12px;
            font-weight:600;
        }
        .user-name-text {
            font-size:14px;
        }
        .user-menu-menu {
            position:absolute;
            right:0;
            top:calc(100% + 4px);
            background:var(--bg);
            color:var(--text);
            border:1px solid var(--border-subtle);
            border-radius:6px;
            box-shadow:0 10px 20px rgba(0,0,0,.08);
            min-width:200px;
            z-index:40;
            padding:4px 0;
        }
        .user-menu-menu a,
        .user-menu-menu .user-menu-button {
            display:block;
            width:100%;
            text-align:left;
            padding:8px 12px;
            font-size:14px;
            text-decoration:none;
            color:inherit;
            background:none;
            border:none;
            cursor:pointer;
        }
        .user-menu-menu a:hover,
        .user-menu-menu .user-menu-button:hover {
            background:var(--surface-muted);
        }
        .role-filter-select {
            border-radius: 12px;
            background: var(--surface-muted);
            border: 1px solid var(--border-subtle);
            padding: 6px 32px 6px 12px;
            font-size: 14px;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-clip: padding-box;
        }
        /* Role filter dropdown (Users page) */
        .role-filter {
            display: inline-block;
            position: relative;
        }

        .role-filter details {
            position: relative;
        }

        .role-filter summary {
            list-style: none;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 12px;
            background: var(--surface-muted);
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .role-filter summary::-webkit-details-marker {
            display: none;
        }

        .role-filter summary::after {
            content: "▾";
            font-size: 12px;
            color: var(--text-muted);
        }

        .role-filter-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border-subtle);
            border-radius: 6px;
            box-shadow: 0 10px 20px rgba(0,0,0,.08);
            min-width: 140px;
            z-index: 40;
            padding: 4px 0;
        }

        .role-filter-menu a {
            display: block;
            padding: 6px 12px;
            font-size: 14px;
            text-decoration: none;
            color: inherit;
        }

        .role-filter-menu a:hover {
            background: var(--surface-muted);
        }

        .role-filter-wrapper {
            position: relative;
            display: inline-block;
        }

        .role-filter-wrapper::after {
            content: "▾";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: var(--text-muted);
            pointer-events: none;
        }

        /* Soft, defined table styling */
        main table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 14px;
            background: var(--surface-elevated);
            color: var(--text);
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            margin-bottom: 16px;
        }
        main table thead th {
            background: var(--surface-muted);
            font-weight: 600;
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-subtle);
        }
        main table tbody td {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-subtle);
        }
        main table tbody tr:last-child td {
            border-bottom: none;
        }
        main table tbody tr:nth-child(even) {
            background: color-mix(in srgb, var(--surface-muted) 70%, var(--surface-elevated) 30%);
        }
        main table tbody tr:hover {
            background: var(--surface-muted);
        }

        /* Form field defaults */
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select,
        textarea {
            color: var(--text);
            background: color-mix(in srgb, var(--bg) 88%, #ffffff 12%);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 9px 10px;
        }

                /* Fancy select (uses same pill look as filters) */
        .fancy-select-wrapper {
            position: relative;
            display: inline-block;
        }

        .fancy-select {
            border-radius: 12px;
            background: var(--surface-muted);
            border: 1px solid var(--border-subtle);
            padding: 6px 32px 6px 12px;
            font-size: 14px;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-clip: padding-box;
            color: var(--text);
        }

        .fancy-select-wrapper::after {
            content: "▾";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: var(--text-muted);
            pointer-events: none;
        }

        /* Client picker – searchable multi-select dropdown */
        .client-picker {
            position: relative;
            max-width: 100%;
        }

        .client-picker-toggle {
            border-radius: 12px;
            background: var(--surface-muted);
            border: 1px solid var(--border-subtle);
            padding: 6px 12px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            cursor: pointer;
            min-width: 260px;
        }

        .client-picker-label {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .client-picker-arrow {
            font-size: 12px;
            color: var(--text-muted);
        }

        .client-picker-panel {
            position: absolute;
            margin-top: 4px;
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border-subtle);
            border-radius: 6px;
            box-shadow: 0 10px 20px rgba(0,0,0,.08);
            min-width: 280px;
            max-width: 360px;
            z-index: 40;
            padding: 8px;
            display: none;
        }

        .client-picker-panel.open {
            display: block;
        }

        .client-picker-search {
            width: 100%;
            padding: 6px 8px;
            border-radius: 4px;
            border: 1px solid var(--border-subtle);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .client-picker-list {
            max-height: 220px;
            overflow: auto;
        }

        .client-picker-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            margin-bottom: 4px;
            cursor: pointer;
        }

        .client-picker-item input[type="checkbox"] {
            margin: 0;
        }

    </style>
</head>
<body>
<div style="display:flex;">
    <aside class="sidebar">
        <div style="padding:16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--sidebar-border);">
            <img class="logo"
                 src="{{ \Storage::url(data_get(\App\Models\Setting::get('branding'), 'logo', '')) }}"
                 alt="Logo">
            <strong>DomainDash</strong>
        </div>
        <nav>
            <ul>
                <!-- Dashboard (no submenu) -->
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        <span class="icon">🏠</span>
                        <span class="text">Dashboard</span>
                    </a>
                </li>

                <!-- Domains (with submenu) -->
                <li class="nav-item nav-section {{ request()->routeIs('admin.domains*') ? 'expanded' : '' }}">
                    <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                        <span class="icon">🌐</span>
                        <span class="text">Domains</span>
                        <span class="arrow">▶</span>
                    </div>
                    <ul class="nav-submenu">
                        <li class="nav-item">
                            <a href="{{ route('admin.domains') }}" class="nav-link {{ request()->routeIs('admin.domains') && !request()->routeIs('admin.domains.purchase') && !request()->routeIs('admin.domains.transfer') ? 'active' : '' }}">
                                Manage
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.domains.transfer.create') }}" class="nav-link {{ request()->routeIs('admin.domains.transfer*') ? 'active' : '' }}">
                                New Transfer
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.domains.purchase') }}" class="nav-link {{ request()->routeIs('admin.domains.purchase') ? 'active' : '' }}">
                                Purchase New Domain
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Hosting Services (with submenu) -->
                <li class="nav-item nav-section {{ request()->routeIs('admin.services.hosting*') ? 'expanded' : '' }}">
                    <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                        <span class="icon">🖥️</span>
                        <span class="text">Hosting Services</span>
                        <span class="arrow">▶</span>
                    </div>
                    <ul class="nav-submenu">
                        <li class="nav-item">
                            <a href="{{ route('admin.services.hosting') }}" class="nav-link {{ request()->routeIs('admin.services.hosting') && !request()->routeIs('admin.services.hosting.purchase') ? 'active' : '' }}">
                                Manage Hosting
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.services.hosting.purchase') }}" class="nav-link {{ request()->routeIs('admin.services.hosting.purchase') ? 'active' : '' }}">
                                Purchase Hosting
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- SSLs (with submenu) -->
                <li class="nav-item nav-section {{ request()->routeIs('admin.services.ssl*') ? 'expanded' : '' }}">
                    <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                        <span class="icon">🔒</span>
                        <span class="text">SSLs</span>
                        <span class="arrow">▶</span>
                    </div>
                    <ul class="nav-submenu">
                        <li class="nav-item">
                            <a href="{{ route('admin.services.ssls') }}" class="nav-link {{ request()->routeIs('admin.services.ssls') && !request()->routeIs('admin.services.ssl.purchase') ? 'active' : '' }}">
                                Manage SSLs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.services.ssl.purchase') }}" class="nav-link {{ request()->routeIs('admin.services.ssl.purchase') ? 'active' : '' }}">
                                Purchase SSL
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Admin Section (hidden for non-admin users) -->
                @role('Administrator')
                    <li class="nav-item nav-section {{ request()->routeIs('admin.clients*') || request()->routeIs('admin.users*') || request()->routeIs('admin.settings') || request()->routeIs('admin.apikeys') || request()->routeIs('admin.dashboard') || request()->routeIs('admin.domains.pricing*') ? 'expanded' : '' }}">
                        <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                            <span class="icon">⚙️</span>
                            <span class="text">Admin</span>
                            <span class="arrow">▶</span>
                        </div>
                        <ul class="nav-submenu">
                            <li class="nav-item">
                                <a href="{{ route('admin.clients.index') }}" class="nav-link {{ request()->routeIs('admin.clients*') ? 'active' : '' }}">
                                    Clients
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.users') }}" class="nav-link {{ request()->routeIs('admin.users*') ? 'active' : '' }}">
                                    Users
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.permissions') }}" class="nav-link {{ request()->routeIs('admin.permissions*') ? 'active' : '' }}">
                                    Roles & Permissions
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.apikeys') }}" class="nav-link {{ request()->routeIs('admin.apikeys') ? 'active' : '' }}">
                                    API Keys
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.settings') }}" class="nav-link {{ request()->routeIs('admin.settings') ? 'active' : '' }}">
                                    Settings
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                                    System Status
                                </a>
                            </li>
                            @can('domain-pricing.view')
                            <li class="nav-item"> 
                                <a href="{{ route('admin.domains.pricing') }}" class="nav-link {{ request()->routeIs('admin.domains.pricing*') ? 'active' : '' }}"> 
                                    Domain Pricing
                                </a>
                            </li>
                            @endcan
                        </ul>
                    </li>
                @endrole
            </ul>
        </nav>
    </aside>

    <script>
        function toggleNav(element) {
            const section = element.closest('.nav-section');
            section.classList.toggle('expanded');
        }
    </script>

    <main style="flex:1;">
        <div class="topbar">
            <div class="topbar-left">
                {{-- Placeholder for page title / breadcrumbs if needed --}}
            </div>

            <div class="topbar-right">
                <div class="user-menu">
                    <details>
                        <summary>
                            <span class="user-avatar-circle">
                                {{ strtoupper(mb_substr(auth()->user()->name ?? 'U', 0, 1)) }}
                            </span>
                            <span class="user-name-text">
                                {{ auth()->user()->name ?? 'User' }}
                            </span>
                        </summary>
                        <div class="user-menu-menu">
                            <a href="{{ route('tickets.create') }}">Log support ticket</a>

                            <form method="POST" action="{{ route('me.toggle-dark') }}">
                                @csrf
                                <button type="submit" class="user-menu-button">
                                    Toggle dark mode
                                </button>
                            </form>

                            <form method="POST" action="/logout">
                                @csrf
                                <button type="submit" class="user-menu-button">
                                    Logout
                                </button>
                            </form>
                        </div>
                    </details>
                </div>

                <div class="notif" x-data="{open:false}" @click="open=!open" :class="{'open': open}">
                    <span>🔔</span>
                    <span class="badge-red">{{ session('notifications_count', 0) }}</span>
                    <div class="panel">
                        <div style="padding:8px 12px;"><strong>Notifications</strong></div>
                        <div style="max-height:300px;overflow:auto;">
                            @foreach(session('notifications',[]) as $n)
                                <div style="padding:8px 12px;border-top:1px solid var(--border-subtle);">{!! $n !!}</div>
                            @endforeach
                            @if(empty(session('notifications')))
                                <div style="padding:8px 12px;color:var(--text-muted);">No new notifications.</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div style="padding:20px;">
            @php $isImpersonating = session()->has('impersonate_as'); @endphp
            @if (session('status') || $isImpersonating)
                <div class="dd-flash-banner {{ $isImpersonating ? 'is-impersonating' : 'is-status' }}">
                    <span>
                        {{ session('status') ?? ('You are currently impersonating '.(auth()->user()->email ?? 'this user').'.') }}
                    </span>
                    @if ($isImpersonating)
                        <form method="POST" action="{{ route('admin.users.stop-impersonate') }}">
                            @csrf
                            <button type="submit" class="dd-flash-action">Stop impersonating</button>
                        </form>
                    @endif
                </div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
