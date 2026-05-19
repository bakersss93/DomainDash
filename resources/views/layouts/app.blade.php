<!DOCTYPE html>
<html lang="en" class="{{ (auth()->user()->dark_mode ?? false) ? 'dark' : '' }}"
      xmlns:x-on="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name','DomainDash') }}</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    @php
        $branding = \App\Models\Setting::get('branding');
    @endphp
    <style>
        :root {
            --primary: {{ data_get($branding, 'primary', '#1f2937') }};
            --accent:  {{ data_get($branding, 'accent', '#06b6d4') }};
            --bg:      {{ data_get($branding, 'bg', '#ffffff') }};
            --text:    {{ data_get($branding, 'text', '#111827') }};
            --surface-muted: color-mix(in srgb, var(--bg) 84%, #dbe3ee 16%);
            --surface-elevated: color-mix(in srgb, var(--bg) 92%, #ffffff 8%);
            --border-subtle: color-mix(in srgb, var(--text) 12%, #dbe3ee 88%);
            --text-muted: color-mix(in srgb, var(--text) 62%, #94a3b8 38%);
            --sidebar-bg: color-mix(in srgb, var(--primary) 90%, #111827 10%);
            --sidebar-bg-soft: color-mix(in srgb, var(--primary) 78%, #1e293b 22%);
            --sidebar-border: rgba(255, 255, 255, 0.14);
            --nav-hover: rgba(255, 255, 255, 0.12);
            --nav-active: var(--accent);
            --accent-contrast: {{ data_get($branding, 'button_text', '#ffffff') }};
            --success-bg: #dcfce7;
            --success-border: #86efac;
            --success-text: #14532d;
            --warning-bg: #fef3c7;
            --warning-border: #fcd34d;
            --warning-text: #78350f;
            --danger-text: #dc2626;
        }

        /* Dark mode: branding-driven palette with sensible fallbacks. */
        html.dark {
            --primary: {{ data_get($branding, 'primary_dark', '#0b1220') }};
            --accent:  {{ data_get($branding, 'accent_dark', '#22d3ee') }};
            --bg:      {{ data_get($branding, 'bg_dark', '#0f172a') }};
            --text:    {{ data_get($branding, 'text_dark', '#e2e8f0') }};
            --surface-muted: color-mix(in srgb, var(--bg) 80%, #1e293b 20%);
            --surface-elevated: color-mix(in srgb, var(--bg) 88%, #0b1220 12%);
            --border-subtle: color-mix(in srgb, var(--text) 18%, #334155 82%);
            --text-muted: color-mix(in srgb, var(--text) 60%, #94a3b8 40%);
            --sidebar-bg: color-mix(in srgb, var(--primary) 88%, #000000 12%);
            --sidebar-bg-soft: color-mix(in srgb, var(--primary) 76%, #1e293b 24%);
            --sidebar-border: rgba(148, 163, 184, 0.2);
            --nav-hover: rgba(148, 163, 184, 0.14);
            --nav-active: var(--accent);
            --accent-contrast: {{ data_get($branding, 'button_text_dark', '#0f172a') }};
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
            flex-shrink: 0;
        }

        .nav-link .icon svg {
            display: block;
        }

        .nav-link .text {
            flex: 1;
        }

        .nav-link .arrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            transition: transform 0.2s;
            color: var(--text-muted, currentColor);
            opacity: 0.7;
        }

        .nav-link .arrow svg {
            display: block;
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

        .nav-submenu.nested {
            padding-left: 18px;
        }

        .nav-submenu .nav-section > .nav-toggle {
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

        .notif-trigger {
            cursor: pointer;
            border: none;
            background: transparent;
            color: inherit;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 16px;
        }

        .notif-modal {
            border: none;
            border-radius: 14px;
            padding: 0;
            width: min(92vw, 520px);
            max-height: 78vh;
        }

        .notif-modal-panel {
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            background: var(--bg);
            color: var(--text);
            overflow: hidden;
        }

        .notif-list {
            max-height: 52vh;
            overflow: auto;
        }

        .notif-item {
            padding: 12px 14px;
            border-top: 1px solid var(--border-subtle);
        }

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


        .mfa-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.72);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 80;
            padding: 20px;
        }

        .mfa-modal-backdrop.is-visible {
            display: flex;
        }

        .mfa-modal {
            width: min(720px, 94vw);
            border-radius: 16px;
            border: 1px solid var(--border-subtle);
            background: var(--bg);
            box-shadow: 0 28px 56px rgba(2, 6, 23, 0.5);
            padding: 20px;
        }

        .mfa-modal h2 {
            margin: 0;
            font-size: 1.25rem;
        }

        .mfa-modal p {
            color: var(--text-muted);
            font-size: 0.92rem;
            margin-top: 6px;
        }

        .mfa-modal-qr {
            margin-top: 14px;
            background: #ffffff;
            border-radius: 12px;
            padding: 14px;
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mfa-step {
            display: none;
        }

        .mfa-step.is-visible {
            display: block;
        }

        .mfa-modal-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 14px;
        }

        .mfa-modal-grid input {
            width: 100%;
        }

        .mfa-step-note {
            margin-top: 10px;
            font-size: 0.84rem;
            color: var(--text-muted);
        }

        .mfa-link-btn {
            background: transparent;
            border: none;
            color: var(--accent);
            text-decoration: underline;
            font-size: 0.88rem;
            padding: 0;
            cursor: pointer;
        }

        .mfa-modal-actions {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .mfa-status {
            margin-top: 10px;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .mfa-status.is-error {
            color: var(--danger-text);
        }

        .mfa-status.is-ok {
            color: var(--success-text);
        }


        .account-modal-backdrop {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.72);
            z-index: 85;
            padding: 20px;
        }

        .account-modal-backdrop.is-open {
            display: flex;
        }

        .account-modal {
            width: min(640px, 95vw);
            border-radius: 16px;
            border: 1px solid var(--border-subtle);
            background: var(--bg);
            box-shadow: 0 24px 48px rgba(2, 6, 23, 0.52);
            padding: 18px;
        }

        .account-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .account-modal-grid {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .account-modal-grid label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .account-modal-grid input {
            width: 100%;
            border-radius: 12px;
        }

        .account-modal-actions {
            margin-top: 14px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .account-action-list {
            margin-top: 14px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .account-status {
            margin-top: 8px;
            font-size: 0.84rem;
            font-weight: 600;
        }

        .account-status.is-error {
            color: var(--danger-text);
        }

        .account-status.is-ok {
            color: var(--success-text);
        }

    </style>
</head>
<body>
@php
    $unreadNotifications = \App\Models\UserNotification::query()
        ->where('user_id', auth()->id())
        ->whereNull('read_at')
        ->latest()
        ->limit(20)
        ->get();
    $unreadNotificationsCount = $unreadNotifications->count();
@endphp
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
                        <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><polyline points="9 21 9 12 15 12 15 21"/></svg></span>
                        <span class="text">Dashboard</span>
                    </a>
                </li>

                <!-- Domains (with submenu) -->
                <li class="nav-item nav-section {{ request()->routeIs('admin.domains*') ? 'expanded' : '' }}">
                    <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                        <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span>
                        <span class="text">Domains</span>
                        <span class="arrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
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

                <!-- Services (with nested submenus for future expansion) -->
                <li class="nav-item nav-section {{ request()->routeIs('admin.services.*') ? 'expanded' : '' }}">
                    <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                        <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                        <span class="text">Services</span>
                        <span class="arrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </div>
                    <ul class="nav-submenu">
                        <li class="nav-item nav-section {{ request()->routeIs('admin.services.ssl*') || request()->routeIs('admin.services.ssls') ? 'expanded' : '' }}">
                            <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                                <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                                <span class="text">SSLs</span>
                                <span class="arrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                            </div>
                            <ul class="nav-submenu nested">
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
                        <li class="nav-item nav-section {{ request()->routeIs('admin.services.hosting*') ? 'expanded' : '' }}">
                            <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                                <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span>
                                <span class="text">Hosting Services</span>
                                <span class="arrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                            </div>
                            <ul class="nav-submenu nested">
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
                        <li class="nav-item nav-section {{ request()->routeIs('admin.services.internet*') ? 'expanded' : '' }}">
                            <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                                <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg></span>
                                <span class="text">Internet</span>
                                <span class="arrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                            </div>
                            <ul class="nav-submenu nested">
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet') }}" class="nav-link {{ request()->routeIs('admin.services.internet') ? 'active' : '' }}">
                                        Manage Internet
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet.qualify') }}" class="nav-link {{ request()->routeIs('admin.services.internet.qualify') ? 'active' : '' }}">
                                        Qualify Address
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet.order') }}" class="nav-link {{ request()->routeIs('admin.services.internet.order') ? 'active' : '' }}">
                                        New Order
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet.transfer') }}" class="nav-link {{ request()->routeIs('admin.services.internet.transfer') ? 'active' : '' }}">
                                        Transfer Service
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>

                <li class="nav-item nav-section {{ request()->routeIs('tickets.*') ? 'expanded' : '' }}">
                    <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                        <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                        <span class="text">Support</span>
                        <span class="arrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </div>
                    <ul class="nav-submenu">
                        <li class="nav-item">
                            <a href="{{ route('tickets.create') }}" class="nav-link {{ request()->routeIs('tickets.create') ? 'active' : '' }}">
                                Log Support Ticket
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('tickets.index') }}" class="nav-link {{ request()->routeIs('tickets.index') ? 'active' : '' }}">
                                View Support Requests
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Admin Section (hidden for non-admin users) -->
                @role('Administrator')
                    <li class="nav-item nav-section {{ request()->routeIs('admin.clients*') || request()->routeIs('admin.users*') || request()->routeIs('admin.settings') || request()->routeIs('admin.notifications.templates*') || request()->routeIs('admin.apikeys') || request()->routeIs('admin.dashboard') || request()->routeIs('admin.domains.pricing*') || request()->routeIs('admin.audit.*') ? 'expanded' : '' }}">
                        <div class="nav-link nav-toggle" onclick="toggleNav(this)">
                            <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                            <span class="text">Admin</span>
                            <span class="arrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
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
                                <a href="{{ route('admin.notifications.templates') }}" class="nav-link {{ request()->routeIs('admin.notifications.templates*') ? 'active' : '' }}">
                                    Email Templates
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                                    System Status
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.audit.index') }}" class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}">
                                    Audit & Logging
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const openButton = document.getElementById('open-notifications-modal');
            const closeButton = document.getElementById('close-notifications-modal');
            const notifModal = document.getElementById('notifications-modal');

            if (openButton && closeButton && notifModal) {
                openButton.addEventListener('click', function () { notifModal.showModal(); });
                closeButton.addEventListener('click', function () { notifModal.close(); });
                notifModal.addEventListener('click', function (event) {
                    if (event.target === notifModal) {
                        notifModal.close();
                    }
                });
            }
        });
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
                            <a href="{{ route('tickets.index') }}">Support requests</a>

                            <button type="button" class="user-menu-button" id="open-account-settings">
                                Account Settings
                            </button>

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

                <button type="button" class="notif-trigger" id="open-notifications-modal" aria-label="Open notifications">
                    <span>🔔</span>
                    <span class="badge-red">{{ $unreadNotificationsCount }}</span>
                </button>

                <dialog id="notifications-modal" class="notif-modal">
                    <div class="notif-modal-panel">
                        <div style="padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                            <strong>Notifications</strong>
                            <button type="button" id="close-notifications-modal" style="border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
                        </div>
                        <div class="notif-list">
                            @forelse($unreadNotifications as $notification)
                                <div class="notif-item">
                                    <div style="font-size:14px;font-weight:700;margin-bottom:4px;">{{ $notification->title }}</div>
                                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px;">{{ $notification->message }}</div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                        @if($notification->link_url)
                                            <a href="{{ $notification->link_url }}" style="font-size:12px;color:var(--accent);text-decoration:none;">Open</a>
                                        @else
                                            <span></span>
                                        @endif
                                        <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                            @csrf
                                            <button type="submit" style="border:1px solid var(--border-subtle);background:var(--surface-muted);border-radius:999px;padding:5px 10px;font-size:12px;cursor:pointer;">Mark as read</button>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <div style="padding:12px 14px;color:var(--text-muted);">No unread notifications.</div>
                            @endforelse
                        </div>
                    </div>
                </dialog>
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

<div id="accountSettingsModal" class="account-modal-backdrop">
    <div class="account-modal" role="dialog" aria-modal="true" aria-labelledby="accountSettingsTitle">
        <div class="account-modal-header">
            <h2 id="accountSettingsTitle" style="margin:0;font-size:1.2rem;">Account Settings</h2>
            <button type="button" class="mfa-link-btn" id="closeAccountSettings">Close</button>
        </div>

        <div class="account-modal-grid">
            <div>
                <label for="accountName">Name</label>
                <input type="text" id="accountName">
            </div>
            <div>
                <label for="accountEmail">Email</label>
                <input type="email" id="accountEmail">
            </div>
        </div>

        <div id="accountStatus" class="account-status" aria-live="polite"></div>

        <div class="account-action-list">
            <button type="button" class="btn-accent" id="openPasswordModal">Change Password</button>
            <button type="button" class="btn-accent" id="accountReenrollMfa">Re-enroll MFA</button>
        </div>

        <div class="account-modal-grid" id="reenrollMfaCodeWrap" style="display:none;">
            <div>
                <label for="reenrollMfaCode">MFA code required for re-enroll</label>
                <input type="text" id="reenrollMfaCode" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter current MFA code">
            </div>
        </div>

        <div class="account-modal-actions">
            <button type="button" class="btn-accent" style="background:var(--surface-muted);color:var(--text);" id="cancelAccountSave">Cancel</button>
            <button type="button" class="btn-accent" id="saveAccountSettings">Save Profile</button>
        </div>
    </div>
</div>

<div id="changePasswordModal" class="account-modal-backdrop">
    <div class="account-modal" role="dialog" aria-modal="true" aria-labelledby="changePasswordTitle">
        <div class="account-modal-header">
            <h2 id="changePasswordTitle" style="margin:0;font-size:1.2rem;">Change Password</h2>
            <button type="button" class="mfa-link-btn" id="closePasswordModal">Close</button>
        </div>

        <div class="account-modal-grid">
            <div>
                <label for="accountCurrentPassword">Current password</label>
                <input type="password" id="accountCurrentPassword">
            </div>
            <div>
                <label for="accountNewPassword">New password</label>
                <input type="password" id="accountNewPassword">
            </div>
            <div>
                <label for="accountNewPasswordConfirm">Confirm new password</label>
                <input type="password" id="accountNewPasswordConfirm">
            </div>
            <div id="passwordMfaCodeWrap" style="display:none;">
                <label for="passwordMfaCode">MFA code required for password change</label>
                <input type="text" id="passwordMfaCode" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter MFA code">
            </div>
        </div>

        <div id="passwordStatus" class="account-status" aria-live="polite"></div>

        <div class="account-modal-actions">
            <button type="button" class="btn-accent" style="background:var(--surface-muted);color:var(--text);" id="cancelPasswordChange">Cancel</button>
            <button type="button" class="btn-accent" id="submitPasswordChange">Update Password</button>
        </div>
    </div>
</div>

@php
    $mfaSetupSession = session('mfa.setup', []);
    $showMfaModal = (bool) ($mfaSetupSession['show'] ?? false);
    $forceMfaModal = (bool) ($mfaSetupSession['required'] ?? false);
@endphp

<div id="mfaSetupModal" class="mfa-modal-backdrop {{ $showMfaModal ? 'is-visible' : '' }}" data-required="{{ $forceMfaModal ? '1' : '0' }}">
    <div class="mfa-modal" role="dialog" aria-modal="true" aria-labelledby="mfaSetupTitle">
        <h2 id="mfaSetupTitle">Secure your account with MFA</h2>

        <section class="mfa-step is-visible" id="mfaStepIntro">
            <p>Would you like to configure MFA now to better secure your login?</p>
            <div class="mfa-modal-actions">
                @if (! $forceMfaModal)
                    <button type="button" class="btn-accent" style="background:var(--surface-muted);color:var(--text);" id="dismissMfaSetup">Remind me later</button>
                @endif
                <button type="button" class="btn-accent" id="mfaIntroNext">Next</button>
            </div>
        </section>

        <section class="mfa-step" id="mfaStepQr">
            <p>Step 2 of 3: Use Google Authenticator (or any TOTP app) and scan the QR code below.</p>
            <div class="mfa-modal-qr" id="mfaQrContainer">
                <span id="mfaLoadingText">Generating QR code...</span>
            </div>
            <div class="mfa-modal-grid">
                <div>
                    <label for="mfaSetupKey" style="display:block;font-size:0.84rem;margin-bottom:4px;">Setup Key</label>
                    <input id="mfaSetupKey" type="text" readonly>
                </div>
            </div>
            <p class="mfa-step-note">If scanning fails, manually enter the setup key in your authenticator app.</p>
            <div class="mfa-modal-actions">
                @if (! $forceMfaModal)
                    <button type="button" class="mfa-link-btn" id="cancelMfaSetup">Cancel</button>
                @endif
                <button type="button" class="btn-accent" id="mfaQrNext">Next</button>
            </div>
        </section>

        <section class="mfa-step" id="mfaStepVerify">
            <p>Step 3 of 3: Enter the 6-digit code generated by your authenticator app to finish setup.</p>
            <div class="mfa-modal-grid">
                <div>
                    <label for="mfaCodeInput" style="display:block;font-size:0.84rem;margin-bottom:4px;">Authenticator code</label>
                    <input id="mfaCodeInput" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="Enter 6-digit code">
                </div>
            </div>
            <div id="mfaStatus" class="mfa-status" aria-live="polite"></div>
            <div class="mfa-modal-actions">
                <button type="button" class="btn-accent" style="background:var(--surface-muted);color:var(--text);" id="mfaVerifyBack">Back</button>
                <button type="button" class="btn-accent" id="confirmMfaSetup">Verify and save MFA</button>
            </div>
        </section>
    </div>
</div>

<script>
    (function () {
        const openBtn = document.getElementById('open-account-settings');
        const closeBtn = document.getElementById('closeAccountSettings');
        const cancelBtn = document.getElementById('cancelAccountSave');
        const saveBtn = document.getElementById('saveAccountSettings');
        const modal = document.getElementById('accountSettingsModal');
        const nameInput = document.getElementById('accountName');
        const emailInput = document.getElementById('accountEmail');
        const status = document.getElementById('accountStatus');
        const openPasswordBtn = document.getElementById('openPasswordModal');
        const reenrollBtn = document.getElementById('accountReenrollMfa');
        const passwordModal = document.getElementById('changePasswordModal');
        const closePasswordBtn = document.getElementById('closePasswordModal');
        const cancelPasswordBtn = document.getElementById('cancelPasswordChange');
        const submitPasswordBtn = document.getElementById('submitPasswordChange');
        const currentPasswordInput = document.getElementById('accountCurrentPassword');
        const newPasswordInput = document.getElementById('accountNewPassword');
        const newPasswordConfirmInput = document.getElementById('accountNewPasswordConfirm');
        const passwordStatus = document.getElementById('passwordStatus');
        const passwordMfaCodeWrap = document.getElementById('passwordMfaCodeWrap');
        const passwordMfaCodeInput = document.getElementById('passwordMfaCode');
        const reenrollMfaCodeWrap = document.getElementById('reenrollMfaCodeWrap');
        const reenrollMfaCodeInput = document.getElementById('reenrollMfaCode');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        if (!openBtn || !modal) {
            return;
        }

        let mfaConfigured = false;

        function setStatus(el, message, tone = '') {
            if (!el) {
                return;
            }
            el.textContent = message;
            el.className = 'account-status' + (tone ? ' ' + tone : '');
        }

        async function request(url, payload = null) {
            const options = {
                method: payload ? 'POST' : 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json'
                }
            };

            if (payload) {
                options.body = JSON.stringify(payload);
            }

            const response = await fetch(url, options);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Request failed');
            }

            return data;
        }

        function closeModal(target) {
            if (target) {
                target.classList.remove('is-open');
            }
        }

        function openModal(target) {
            if (target) {
                target.classList.add('is-open');
            }
        }

        async function loadAccount() {
            setStatus(status, 'Loading account details...');
            const data = await request('{{ route('me.account.details') }}');
            nameInput.value = data.name || '';
            emailInput.value = data.email || '';
            mfaConfigured = !!data.mfa_configured;
            if (passwordMfaCodeWrap) {
                passwordMfaCodeWrap.style.display = 'none';
            }
            if (reenrollMfaCodeWrap) {
                reenrollMfaCodeWrap.style.display = 'none';
            }
            if (passwordMfaCodeInput) {
                passwordMfaCodeInput.value = '';
            }
            if (reenrollMfaCodeInput) {
                reenrollMfaCodeInput.value = '';
            }
            setStatus(status, '', '');
        }

        openBtn.addEventListener('click', async function () {
            try {
                await loadAccount();
                openModal(modal);
            } catch (error) {
                setStatus(status, error.message, 'is-error');
                openModal(modal);
            }
        });

        [closeBtn, cancelBtn].forEach((btn) => {
            if (btn) {
                btn.addEventListener('click', function () {
                    closeModal(modal);
                });
            }
        });

        saveBtn.addEventListener('click', async function () {
            try {
                await request('{{ route('me.account.update') }}', {
                    name: nameInput.value,
                    email: emailInput.value
                });
                setStatus(status, 'Account updated successfully.', 'is-ok');
            } catch (error) {
                setStatus(status, error.message, 'is-error');
            }
        });

        if (openPasswordBtn) {
            openPasswordBtn.addEventListener('click', function () {
                setStatus(passwordStatus, '', '');
                if (passwordMfaCodeWrap) {
                    passwordMfaCodeWrap.style.display = mfaConfigured ? 'block' : 'none';
                }
                if (passwordMfaCodeInput) {
                    passwordMfaCodeInput.value = '';
                }
                openModal(passwordModal);
            });
        }

        [closePasswordBtn, cancelPasswordBtn].forEach((btn) => {
            if (btn) {
                btn.addEventListener('click', function () {
                    closeModal(passwordModal);
                });
            }
        });

        if (submitPasswordBtn) {
            submitPasswordBtn.addEventListener('click', async function () {
                try {
                    await request('{{ route('me.account.password') }}', {
                        current_password: currentPasswordInput.value,
                        password: newPasswordInput.value,
                        password_confirmation: newPasswordConfirmInput.value,
                        mfa_code: mfaConfigured ? (passwordMfaCodeInput?.value || '') : ''
                    });
                    setStatus(passwordStatus, 'Password updated successfully.', 'is-ok');
                    currentPasswordInput.value = '';
                    newPasswordInput.value = '';
                    newPasswordConfirmInput.value = '';
                } catch (error) {
                    setStatus(passwordStatus, error.message, 'is-error');
                }
            });
        }

        if (reenrollBtn) {
            reenrollBtn.addEventListener('click', async function () {
                if (mfaConfigured && reenrollMfaCodeWrap && reenrollMfaCodeInput && !reenrollMfaCodeInput.value.trim()) {
                    reenrollMfaCodeWrap.style.display = 'block';
                    setStatus(status, 'Enter your current MFA code, then click Re-enroll MFA again.', 'is-error');
                    reenrollMfaCodeInput.focus();
                    return;
                }

                try {
                    await request('{{ route('me.account.mfa-reenroll') }}', {
                        mfa_code: mfaConfigured ? (reenrollMfaCodeInput?.value || '') : ''
                    });
                    window.location.reload();
                } catch (error) {
                    setStatus(status, error.message, 'is-error');
                }
            });
        }

        [modal, passwordModal].forEach((dlg) => {
            if (!dlg) {
                return;
            }
            dlg.addEventListener('click', function (event) {
                if (event.target === dlg) {
                    closeModal(dlg);
                }
            });
        });
    })();
</script>

<script>
    (function () {
        const modal = document.getElementById('mfaSetupModal');
        if (!modal || !modal.classList.contains('is-visible')) {
            return;
        }

        const stepIntro = document.getElementById('mfaStepIntro');
        const stepQr = document.getElementById('mfaStepQr');
        const stepVerify = document.getElementById('mfaStepVerify');
        const qrContainer = document.getElementById('mfaQrContainer');
        const setupKeyInput = document.getElementById('mfaSetupKey');
        const codeInput = document.getElementById('mfaCodeInput');
        const status = document.getElementById('mfaStatus');
        const dismissBtn = document.getElementById('dismissMfaSetup');
        const cancelBtn = document.getElementById('cancelMfaSetup');
        const introNextBtn = document.getElementById('mfaIntroNext');
        const qrNextBtn = document.getElementById('mfaQrNext');
        const verifyBackBtn = document.getElementById('mfaVerifyBack');
        const confirmBtn = document.getElementById('confirmMfaSetup');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        function switchStep(step) {
            [stepIntro, stepQr, stepVerify].forEach((el) => {
                el.classList.remove('is-visible');
            });
            step.classList.add('is-visible');
        }

        function setStatus(message, tone = '') {
            status.textContent = message;
            status.className = 'mfa-status' + (tone ? ' ' + tone : '');
        }

        async function request(url, payload = null) {
            const options = {
                method: payload ? 'POST' : 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json'
                }
            };

            if (payload) {
                options.body = JSON.stringify(payload);
            }

            const response = await fetch(url, options);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Request failed');
            }

            return data;
        }

        function renderSetup(data) {
            if (data.configured) {
                setStatus('MFA is already configured.', 'is-ok');
                setTimeout(() => window.location.reload(), 500);
                return;
            }

            setupKeyInput.value = data.setup_key || '';
            qrContainer.innerHTML = data.qr_svg || '<span>Unable to render QR code.</span>';
            setStatus('', '');
        }

        async function prepareQrStep() {
            qrContainer.innerHTML = '<span id="mfaLoadingText">Generating QR code...</span>';
            try {
                const statusData = await request('{{ route('me.mfa.status') }}');
                if (statusData.ready) {
                    renderSetup(statusData);
                    return true;
                }
                const startData = await request('{{ route('me.mfa.start') }}', {});
                renderSetup(startData);
                return true;
            } catch (error) {
                qrContainer.innerHTML = '<span>Could not generate MFA setup.</span>';
                setStatus(error.message, 'is-error');
                return false;
            }
        }

        async function dismissPrompt() {
            try {
                await request('{{ route('me.mfa.dismiss') }}', {});
                modal.classList.remove('is-visible');
            } catch (error) {
                setStatus(error.message, 'is-error');
                switchStep(stepVerify);
            }
        }

        if (dismissBtn) {
            dismissBtn.addEventListener('click', dismissPrompt);
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', dismissPrompt);
        }

        introNextBtn.addEventListener('click', async function () {
            switchStep(stepQr);
            await prepareQrStep();
        });

        qrNextBtn.addEventListener('click', function () {
            switchStep(stepVerify);
            codeInput.focus();
        });

        verifyBackBtn.addEventListener('click', function () {
            switchStep(stepQr);
        });

        confirmBtn.addEventListener('click', async function () {
            const code = codeInput.value.trim();
            if (!code) {
                setStatus('Enter a valid authenticator code.', 'is-error');
                return;
            }

            confirmBtn.disabled = true;
            try {
                await request('{{ route('me.mfa.confirm') }}', { code });
                setStatus('MFA configured successfully. Reloading...', 'is-ok');
                setTimeout(() => window.location.reload(), 600);
            } catch (error) {
                setStatus(error.message, 'is-error');
            } finally {
                confirmBtn.disabled = false;
            }
        });
    })();
</script>

</body>
</html>
