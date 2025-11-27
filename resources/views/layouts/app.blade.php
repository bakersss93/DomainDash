<!DOCTYPE html>
<html lang="en" class="{{ (auth()->user()->dark_mode ?? false) ? 'dark' : '' }}"
      xmlns:x-on="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name','DomainDash') }}</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <style>
        :root {
            --primary: {{ data_get(\App\Models\Setting::get('branding'), 'primary', '#1f2937') }};
            --accent:  {{ data_get(\App\Models\Setting::get('branding'), 'accent', '#06b6d4') }};
            --bg:      {{ data_get(\App\Models\Setting::get('branding'), 'bg', '#ffffff') }};
            --text:    {{ data_get(\App\Models\Setting::get('branding'), 'text', '#111827') }};
        }

        /* Dark mode: invert background & text colours */
        html.dark {
            --bg: #111827;
            --text: #f9fafb;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .sidebar {
            width: 260px;
            background: var(--primary);
            color: #fff;
            min-height: 100vh;
        }

        .topbar {
            height: 56px;
            background: var(--bg);
            color: var(--text);
            border-bottom: 1px solid #e5e7eb;
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
            border:1px solid #e5e7eb;
            box-shadow:0 10px 20px rgba(0,0,0,.08);
            display:none;
            z-index:40;
        }

        .notif.open .panel { display:block; }

        .danger { color:#dc2626; }

        .badge-red {
            color: #fff;
            background:#dc2626;
            border-radius: 9999px;
            padding: 0 8px;
            font-size: 12px;
            margin-left:4px;
        }

        /* Accent button + spinner */
        .btn-accent {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1.2;
        }
        .btn-accent:hover {
            filter: brightness(0.95);
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
            background:#f3f4f6;
        }
        html.dark .user-menu summary {
            background:#1f2937;
            color:#f9fafb;
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
            border:1px solid #e5e7eb;
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
            background:#f3f4f6;
        }
        html.dark .user-menu-menu a:hover,
        html.dark .user-menu-menu .user-menu-button:hover {
            background:#1f2937;
        }
        .role-filter-select {
            border-radius: 9999px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
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
            border-radius: 9999px;
            background: #f3f4f6;
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
            color: #6b7280;
        }

        html.dark .role-filter summary {
            background: #1f2937;
            color: #f9fafb;
        }

        html.dark .role-filter summary::after {
            color: #9ca3af;
        }

        .role-filter-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            background: var(--bg);
            color: var(--text);
            border: 1px solid #e5e7eb;
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
            background: #f3f4f6;
        }

        html.dark .role-filter-menu {
            border-color: #374151;
        }

        html.dark .role-filter-menu a:hover {
            background: #1f2937;
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
            color: #6b7280;
            pointer-events: none;
        }

        html.dark .role-filter-select {
            background: #1f2937;
            border-color: #374151;
            color: #f9fafb;
        }

        html.dark .role-filter-wrapper::after {
            color: #9ca3af;
        }

        /* Soft, defined table styling */
        main table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 14px;
            background: #ffffff;
            color: #111827;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            margin-bottom: 16px;
        }
        main table thead th {
            background: #f9fafb;
            font-weight: 600;
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        main table tbody td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        main table tbody tr:last-child td {
            border-bottom: none;
        }
        main table tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        main table tbody tr:hover {
            background: #e5e7eb;
        }

        /* Dark-mode adaptation for tables */
        html.dark main table {
            background: #111827;
            color: #f9fafb;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
        }
        html.dark main table thead th {
            background: #1f2937;
            border-bottom-color: #374151;
        }
        html.dark main table tbody td {
            border-bottom-color: #374151;
        }
        html.dark main table tbody tr:nth-child(even) {
            background: #111827;
        }
        html.dark main table tbody tr:hover {
            background: #1f2937;
        }
                /* Form field defaults */
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select,
        textarea {
            color: #111827;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 8px;
        }

        html.dark input[type="text"],
        html.dark input[type="email"],
        html.dark input[type="password"],
        html.dark input[type="number"],
        html.dark select,
        html.dark textarea {
            color: #111827;        /* dark text */
            background: #f9fafb;   /* light background box */
            border-color: #e5e7eb;
        }
                /* Fancy select (uses same pill look as filters) */
        .fancy-select-wrapper {
            position: relative;
            display: inline-block;
        }

        .fancy-select {
            border-radius: 9999px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            padding: 6px 32px 6px 12px;
            font-size: 14px;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-clip: padding-box;
            color: #111827;
        }

        .fancy-select-wrapper::after {
            content: "▾";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: #6b7280;
            pointer-events: none;
        }

        html.dark .fancy-select {
            background: #1f2937;
            border-color: #374151;
            color: #f9fafb;
        }

        html.dark .fancy-select-wrapper::after {
            color: #9ca3af;
        }

        /* Client picker – searchable multi-select dropdown */
        .client-picker {
            position: relative;
            max-width: 100%;
        }

        .client-picker-toggle {
            border-radius: 9999px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
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
            color: #6b7280;
        }

        html.dark .client-picker-toggle {
            background: #1f2937;
            border-color: #374151;
            color: #f9fafb;
        }

        html.dark .client-picker-arrow {
            color: #9ca3af;
        }

        .client-picker-panel {
            position: absolute;
            margin-top: 4px;
            background: var(--bg);
            color: var(--text);
            border: 1px solid #e5e7eb;
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
            border: 1px solid #e5e7eb;
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

        html.dark .client-picker-panel {
            border-color: #374151;
        }

    </style>
</head>
<body>
<div style="display:flex;">
    <aside class="sidebar">
        <div style="padding:12px;display:flex;align-items:center;gap:8px;">
            <img class="logo"
                 src="{{ \Storage::url(data_get(\App\Models\Setting::get('branding'), 'logo', '')) }}"
                 alt="Logo">
            <strong>DomainDash</strong>
        </div>
        <nav style="padding:12px;">
            <ul style="list-style:none;padding-left:0;margin:0;">
                <li style="margin-bottom:4px;">
                    <a href="{{ route('dashboard') }}" style="color:#fff;text-decoration:none;">Dashboard</a>
                </li>
                <li style="margin-bottom:4px;">
                    <a href="{{ route('admin.domains') }}" style="color:#fff;text-decoration:none;">Domains</a>
                </li>
                <li style="margin-bottom:4px;">
                    <a href="{{ route('admin.services.hosting') }}" style="color:#fff;text-decoration:none;">Services</a>
                </li>
                <li style="margin-bottom:4px;">
                    <a href="{{ route('admin.services.ssls') }}" style="color:#fff;text-decoration:none;">SSLs</a>
                </li>

                @role('Administrator')
                    <li style="margin-top:12px;opacity:.8;">Admin</li>

                    <li style="margin-bottom:4px;">
                        <a href="{{ route('admin.clients.index') }}" style="color:#fff;text-decoration:none;">Clients</a>
                    </li>
                    <li style="margin-bottom:4px;">
                        <a href="{{ route('admin.users') }}" style="color:#fff;text-decoration:none;">Users</a>
                    </li>

                    <li style="margin-bottom:4px;">
                        <a href="{{ route('admin.settings') }}" style="color:#fff;text-decoration:none;">Settings</a>
                    </li>
                    <li style="margin-bottom:4px;">
                        <a href="{{ route('admin.apikeys') }}" style="color:#fff;text-decoration:none;">API Keys</a>
                    </li>
                @endrole
            </ul>
        </nav>
    </aside>

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
                                <div style="padding:8px 12px;border-top:1px solid #f3f4f6;">{!! $n !!}</div>
                            @endforeach
                            @if(empty(session('notifications')))
                                <div style="padding:8px 12px;color:#6b7280;">No new notifications.</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div style="padding:16px;">
            @if (session('status'))
                <div style="padding:12px;border:1px solid #10b981;background:#d1fae5;border-radius:4px;">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
