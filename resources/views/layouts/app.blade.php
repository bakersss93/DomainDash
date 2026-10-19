<!DOCTYPE html>
<html lang="en" class="{{ (auth()->user()->dark_mode ?? false) ? 'dark' : '' }}"
      xmlns:x-on="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name','DomainDash') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css','resources/js/app.js'])
    @php
        $branding = \App\Models\Setting::get('branding');

        /*
         * Branding overrides: only colours an admin has actually customised are emitted;
         * values equal to the pre-redesign defaults (stored by older settings forms) and
         * anything that is not a hex colour are ignored so the design-system tokens in
         * resources/css/app.css stay authoritative.
         */
        $legacyBrandDefaults = [
            'primary' => '#1f2937', 'accent' => '#06b6d4', 'bg' => '#ffffff', 'text' => '#111827', 'button_text' => '#ffffff',
            'primary_dark' => '#0b1220', 'accent_dark' => '#22d3ee', 'bg_dark' => '#0f172a', 'text_dark' => '#e2e8f0', 'button_text_dark' => '#0f172a',
        ];
        $brandColour = function (string $key) use ($branding, $legacyBrandDefaults) {
            $value = strtolower(trim((string) data_get($branding, $key, '')));
            if (! preg_match('/^#[0-9a-f]{3,8}$/', $value) || $value === ($legacyBrandDefaults[$key] ?? null)) {
                return null;
            }
            return $value;
        };
        $brandCss = function (string $suffix) use ($brandColour) {
            $css = '';
            if ($c = $brandColour('accent'.$suffix)) {
                $css .= "--dd-accent:$c;--dd-accent-strong:color-mix(in srgb,$c 82%,#000);--dd-accent-text:color-mix(in srgb,$c 80%,#000);"
                    ."--dd-accent-soft:color-mix(in srgb,$c 14%,transparent);--dd-sidebar-accent:$c;--dd-focus:$c;";
            }
            if ($c = $brandColour('button_text'.$suffix)) {
                $css .= "--dd-accent-contrast:$c;";
            }
            if ($c = $brandColour('primary'.$suffix)) {
                $css .= "--dd-sidebar:$c;--dd-sidebar-soft:color-mix(in srgb,$c 88%,#fff);";
            }
            if ($c = $brandColour('bg'.$suffix)) {
                $css .= "--dd-canvas:$c;";
            }
            if ($c = $brandColour('text'.$suffix)) {
                $css .= "--dd-text:$c;";
            }
            return $css;
        };
        $brandLight = $brandCss('');
        $brandDark = $brandCss('_dark');
        $brandLogo = data_get($branding, 'logo');
        $logoUrl = $brandLogo ? \Storage::url($brandLogo) : asset('images/jargon-logo.png');
    @endphp
    @if ($brandLight !== '' || $brandDark !== '')
        <style>
            @if ($brandLight !== '') :root { {{ $brandLight }} } @endif
            @if ($brandDark !== '') html.dark { {{ $brandDark }} } @endif
        </style>
    @endif
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
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-md focus:bg-surface focus:px-3 focus:py-2 focus:text-ink focus:shadow-lg">Skip to main content</a>

<div class="app-shell">
    <aside class="sidebar" id="app-sidebar">
        <div class="sidebar-brand">
            <a href="{{ route('dashboard') }}" aria-label="DomainDash home">
                <img class="logo" src="{{ $logoUrl }}" alt="{{ config('app.name', 'DomainDash') }} logo">
                <span class="sidebar-brand__name">DomainDash</span>
            </a>
        </div>
        <nav aria-label="Primary">
            <ul>
                <!-- Dashboard (no submenu) -->
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
                        <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><polyline points="9 21 9 12 15 12 15 21"/></svg></span>
                        <span class="text">Dashboard</span>
                    </a>
                </li>

                <!-- Domains (with submenu) -->
                <li class="nav-item nav-section {{ request()->routeIs('admin.domains*') ? 'expanded' : '' }}">
                    <button type="button" class="nav-link nav-toggle" onclick="toggleNav(this)" aria-expanded="false">
                        <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span>
                        <span class="text">Domains</span>
                        <span class="arrow"><svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </button>
                    <ul class="nav-submenu">
                        <li class="nav-item">
                            <a href="{{ route('admin.domains') }}" class="nav-link {{ request()->routeIs('admin.domains') && !request()->routeIs('admin.domains.purchase') && !request()->routeIs('admin.domains.transfer') ? 'active' : '' }}" @if(request()->routeIs('admin.domains') && !request()->routeIs('admin.domains.purchase') && !request()->routeIs('admin.domains.transfer')) aria-current="page" @endif>
                                Manage
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.domains.transfer.create') }}" class="nav-link {{ request()->routeIs('admin.domains.transfer*') ? 'active' : '' }}" @if(request()->routeIs('admin.domains.transfer*')) aria-current="page" @endif>
                                New Transfer
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.domains.purchase') }}" class="nav-link {{ request()->routeIs('admin.domains.purchase') ? 'active' : '' }}" @if(request()->routeIs('admin.domains.purchase')) aria-current="page" @endif>
                                Purchase New Domain
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Services (with nested submenus for future expansion) -->
                <li class="nav-item nav-section {{ request()->routeIs('admin.services.*') ? 'expanded' : '' }}">
                    <button type="button" class="nav-link nav-toggle" onclick="toggleNav(this)" aria-expanded="false">
                        <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                        <span class="text">Services</span>
                        <span class="arrow"><svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </button>
                    <ul class="nav-submenu">
                        <li class="nav-item nav-section {{ request()->routeIs('admin.services.ssl*') || request()->routeIs('admin.services.ssls') ? 'expanded' : '' }}">
                            <button type="button" class="nav-link nav-toggle" onclick="toggleNav(this)" aria-expanded="false">
                                <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                                <span class="text">SSLs</span>
                                <span class="arrow"><svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                            </button>
                            <ul class="nav-submenu nested">
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.ssls') }}" class="nav-link {{ request()->routeIs('admin.services.ssls') && !request()->routeIs('admin.services.ssl.purchase') ? 'active' : '' }}" @if(request()->routeIs('admin.services.ssls') && !request()->routeIs('admin.services.ssl.purchase')) aria-current="page" @endif>
                                        Manage SSLs
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.ssl.purchase') }}" class="nav-link {{ request()->routeIs('admin.services.ssl.purchase') ? 'active' : '' }}" @if(request()->routeIs('admin.services.ssl.purchase')) aria-current="page" @endif>
                                        Purchase SSL
                                    </a>
                                </li>
                            </ul>
                        </li>
                        <li class="nav-item nav-section {{ request()->routeIs('admin.services.hosting*') ? 'expanded' : '' }}">
                            <button type="button" class="nav-link nav-toggle" onclick="toggleNav(this)" aria-expanded="false">
                                <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span>
                                <span class="text">Hosting Services</span>
                                <span class="arrow"><svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                            </button>
                            <ul class="nav-submenu nested">
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.hosting') }}" class="nav-link {{ request()->routeIs('admin.services.hosting') && !request()->routeIs('admin.services.hosting.purchase') ? 'active' : '' }}" @if(request()->routeIs('admin.services.hosting') && !request()->routeIs('admin.services.hosting.purchase')) aria-current="page" @endif>
                                        Manage Hosting
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.hosting.purchase') }}" class="nav-link {{ request()->routeIs('admin.services.hosting.purchase') ? 'active' : '' }}" @if(request()->routeIs('admin.services.hosting.purchase')) aria-current="page" @endif>
                                        Purchase Hosting
                                    </a>
                                </li>
                            </ul>
                        </li>
                        <li class="nav-item nav-section {{ request()->routeIs('admin.services.internet*') ? 'expanded' : '' }}">
                            <button type="button" class="nav-link nav-toggle" onclick="toggleNav(this)" aria-expanded="false">
                                <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg></span>
                                <span class="text">Internet</span>
                                <span class="arrow"><svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                            </button>
                            <ul class="nav-submenu nested">
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet') }}" class="nav-link {{ request()->routeIs('admin.services.internet') ? 'active' : '' }}" @if(request()->routeIs('admin.services.internet')) aria-current="page" @endif>
                                        Manage Internet
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet.qualify') }}" class="nav-link {{ request()->routeIs('admin.services.internet.qualify') ? 'active' : '' }}" @if(request()->routeIs('admin.services.internet.qualify')) aria-current="page" @endif>
                                        Qualify Address
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet.order') }}" class="nav-link {{ request()->routeIs('admin.services.internet.order') ? 'active' : '' }}" @if(request()->routeIs('admin.services.internet.order')) aria-current="page" @endif>
                                        New Order
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ route('admin.services.internet.transfer') }}" class="nav-link {{ request()->routeIs('admin.services.internet.transfer') ? 'active' : '' }}" @if(request()->routeIs('admin.services.internet.transfer')) aria-current="page" @endif>
                                        Transfer Service
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>

                <li class="nav-item nav-section {{ request()->routeIs('tickets.*') ? 'expanded' : '' }}">
                    <button type="button" class="nav-link nav-toggle" onclick="toggleNav(this)" aria-expanded="false">
                        <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                        <span class="text">Support</span>
                        <span class="arrow"><svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </button>
                    <ul class="nav-submenu">
                        <li class="nav-item">
                            <a href="{{ route('tickets.create') }}" class="nav-link {{ request()->routeIs('tickets.create') ? 'active' : '' }}" @if(request()->routeIs('tickets.create')) aria-current="page" @endif>
                                Log Support Ticket
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('tickets.index') }}" class="nav-link {{ request()->routeIs('tickets.index') ? 'active' : '' }}" @if(request()->routeIs('tickets.index')) aria-current="page" @endif>
                                View Support Requests
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Admin Section (hidden for non-admin users) -->
                @role('Administrator')
                    <li class="nav-item nav-section {{ request()->routeIs('admin.clients*') || request()->routeIs('admin.users*') || request()->routeIs('admin.settings') || request()->routeIs('admin.notifications.templates*') || request()->routeIs('admin.apikeys') || request()->routeIs('admin.dashboard') || request()->routeIs('admin.domains.pricing*') || request()->routeIs('admin.audit.*') ? 'expanded' : '' }}">
                        <button type="button" class="nav-link nav-toggle" onclick="toggleNav(this)" aria-expanded="false">
                            <span class="icon"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                            <span class="text">Admin</span>
                            <span class="arrow"><svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <ul class="nav-submenu">
                            <li class="nav-item">
                                <a href="{{ route('admin.clients.index') }}" class="nav-link {{ request()->routeIs('admin.clients*') ? 'active' : '' }}" @if(request()->routeIs('admin.clients*')) aria-current="page" @endif>
                                    Clients
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.users') }}" class="nav-link {{ request()->routeIs('admin.users*') ? 'active' : '' }}" @if(request()->routeIs('admin.users*')) aria-current="page" @endif>
                                    Users
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.permissions') }}" class="nav-link {{ request()->routeIs('admin.permissions*') ? 'active' : '' }}" @if(request()->routeIs('admin.permissions*')) aria-current="page" @endif>
                                    Roles & Permissions
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.apikeys') }}" class="nav-link {{ request()->routeIs('admin.apikeys') ? 'active' : '' }}" @if(request()->routeIs('admin.apikeys')) aria-current="page" @endif>
                                    API Keys
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.settings') }}" class="nav-link {{ request()->routeIs('admin.settings') ? 'active' : '' }}" @if(request()->routeIs('admin.settings')) aria-current="page" @endif>
                                    Settings
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.notifications.templates') }}" class="nav-link {{ request()->routeIs('admin.notifications.templates*') ? 'active' : '' }}" @if(request()->routeIs('admin.notifications.templates*')) aria-current="page" @endif>
                                    Email Templates
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
                                    System Status
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.audit.index') }}" class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}" @if(request()->routeIs('admin.audit.*')) aria-current="page" @endif>
                                    Audit & Logging
                                </a>
                            </li>
                            @can('domain-pricing.view')
                            <li class="nav-item"> 
                                <a href="{{ route('admin.domains.pricing') }}" class="nav-link {{ request()->routeIs('admin.domains.pricing*') ? 'active' : '' }}" @if(request()->routeIs('admin.domains.pricing*')) aria-current="page" @endif> 
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
    <div class="sidebar-scrim" aria-hidden="true"></div>

    <main class="app-main">
        <div class="topbar">
            <div class="topbar-left">
                <button type="button" class="topbar-icon-btn topbar-menu-btn" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false" aria-label="Toggle navigation menu">
                    <x-ui.icon name="menu" />
                </button>
            </div>

            <div class="topbar-right">
                <form method="POST" action="{{ route('me.toggle-dark') }}">
                    @csrf
                    <button type="submit" class="topbar-icon-btn" aria-label="Switch to {{ (auth()->user()->dark_mode ?? false) ? 'light' : 'dark' }} mode" title="Switch to {{ (auth()->user()->dark_mode ?? false) ? 'light' : 'dark' }} mode">
                        <x-ui.icon :name="(auth()->user()->dark_mode ?? false) ? 'sun' : 'moon'" />
                    </button>
                </form>

                <button type="button" class="topbar-icon-btn notif-trigger" id="open-notifications-modal" aria-label="Open notifications ({{ $unreadNotificationsCount }} unread)">
                    <x-ui.icon name="bell" />
                    @if ($unreadNotificationsCount > 0)
                        <span class="badge-red" aria-hidden="true">{{ $unreadNotificationsCount }}</span>
                    @endif
                </button>

                <dialog id="notifications-modal" class="notif-modal" aria-labelledby="notifications-title">
                    <div class="notif-modal-panel">
                        <div class="notif-modal-header">
                            <h2 id="notifications-title" style="margin:0;font-size:1rem;">Notifications</h2>
                            <button type="button" id="close-notifications-modal" class="topbar-icon-btn" aria-label="Close notifications">
                                <x-ui.icon name="x" />
                            </button>
                        </div>
                        <div class="notif-list">
                            @forelse($unreadNotifications as $notification)
                                <div class="notif-item">
                                    <div class="notif-item__title">{{ $notification->title }}</div>
                                    <div class="notif-item__message">{{ $notification->message }}</div>
                                    <div class="notif-item__actions">
                                        @if($notification->link_url)
                                            <a href="{{ $notification->link_url }}" class="ui-link" style="font-size:0.8125rem;">Open</a>
                                        @else
                                            <span></span>
                                        @endif
                                        <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                            @csrf
                                            <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm">Mark as read</button>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <div class="notif-empty">No unread notifications.</div>
                            @endforelse
                        </div>
                    </div>
                </dialog>

                <div class="user-menu">
                    <details>
                        <summary aria-label="Account menu">
                            <span class="user-avatar-circle" aria-hidden="true">
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
            </div>
        </div>

        <div class="app-content" id="main-content" tabindex="-1">
            @php
                $isImpersonating = session()->has('impersonate_as');
                $statusMessage = session('status');
                $statusIsError = is_string($statusMessage)
                    && preg_match('/\b(unable|failed|failure|error|could not|cannot|can\'t|invalid|denied)\b/i', $statusMessage);
            @endphp
            @if (session('status') || $isImpersonating)
                <div class="dd-flash-banner {{ $isImpersonating ? 'is-impersonating' : ($statusIsError ? 'is-error' : 'is-status') }}"
                     role="{{ ($isImpersonating || $statusIsError) ? 'alert' : 'status' }}">
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
            @if (session('success'))
                <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
            @endif
            @if (session('error'))
                <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </main>
</div>

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

        // Nav sections that start expanded expose that state to assistive tech.
        document.querySelectorAll('.nav-section.expanded > .nav-toggle').forEach(function (el) {
            el.setAttribute('aria-expanded', 'true');
        });
    });
</script>

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
            <button type="button" class="btn-secondary" id="cancelAccountSave">Cancel</button>
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
            <button type="button" class="btn-secondary" id="cancelPasswordChange">Cancel</button>
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
                    <button type="button" class="btn-secondary" id="dismissMfaSetup">Remind me later</button>
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
                <button type="button" class="btn-secondary" id="mfaVerifyBack">Back</button>
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
            emailInput.dataset.original = emailInput.value;
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
                const payload = { name: nameInput.value, email: emailInput.value };
                if (emailInput.dataset.original && emailInput.dataset.original.toLowerCase() !== emailInput.value.toLowerCase()) {
                    const pw = window.prompt('Enter your current password to change your email address:');
                    if (!pw) { setStatus(status, 'Email not changed: password required.', 'is-error'); return; }
                    payload.current_password = pw;
                }
                await request('{{ route('me.account.update') }}', payload);
                emailInput.dataset.original = emailInput.value;
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
