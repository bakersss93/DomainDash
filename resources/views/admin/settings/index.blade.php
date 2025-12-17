@extends('layouts.app')

@section('content')
    <div style="max-width: 900px; margin: 0 auto;">

        {{-- Title --}}
        <h1 style="font-size:24px;font-weight:700;margin-bottom:8px;color:#f8fafc;">
            Settings
        </h1>
        <p style="font-size:14px;color:#94a3b8;margin-bottom:24px;">
            Configure your DomainDash installation and integrations
        </p>

        {{-- Success Message --}}
        @if(session('status'))
            <div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#10b981;">
                <strong>✓</strong> {{ session('status') }}
            </div>
        @endif

        {{-- Error Messages --}}
        @if($errors->any())
            <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#ef4444;">
                <strong>⚠</strong>
                <ul style="margin:4px 0 0 20px;padding:0;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
              action="{{ route('admin.settings.update') }}"
              enctype="multipart/form-data">
            @csrf

            {{-- BRANDING SECTION --}}
            <div class="settings-section" style="background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('branding')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:rgba(15,23,42,0.4);border-bottom:1px solid rgba(148,163,184,0.1);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:linear-gradient(135deg,#06b6d4,#3b82f6);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px;">
                            🎨
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:#f8fafc;">Branding</h3>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">Customize colors and logo</p>
                        </div>
                    </div>
                    <svg id="branding-icon" style="width:20px;height:20px;transition:transform 0.3s;color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="branding-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">Primary Color</label>
                    <input type="color"
                           name="branding[primary]"
                           value="{{ $settings['branding']['primary'] ?? '#1f2937' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">Accent Color</label>
                    <input type="color"
                           name="branding[accent]"
                           value="{{ $settings['branding']['accent'] ?? '#06b6d4' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">Background Color</label>
                    <input type="color"
                           name="branding[bg]"
                           value="{{ $settings['branding']['bg'] ?? '#ffffff' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">Text Color</label>
                    <input type="color"
                           name="branding[text]"
                           value="{{ $settings['branding']['text'] ?? '#111827' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:0;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Logo (PNG, up to ~200px wide)
                    </label>

                    @if (!empty($settings['branding']['logo']))
                        <div style="margin-bottom:8px;">
                            <strong style="font-size:14px;color:#e2e8f0;">Current logo:</strong><br>
                            <img src="{{ Storage::url($settings['branding']['logo']) }}"
                                 alt="Current logo"
                                 style="max-height:80px;max-width:200px;border-radius:4px;margin-top:8px;">
                        </div>
                    @endif

                    <input type="file"
                           name="branding_logo"
                           accept="image/png,image/jpeg,image/svg+xml"
                           style="display:block;width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;background:#0b1120;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        PNG preferred, 200px wide, height auto-scales.
                    </small>
                </div>
                </div>
            </div>

            {{-- SYNERGY WHOLESALE SECTION --}}
            <div class="settings-section" style="background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('synergy')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:rgba(15,23,42,0.4);border-bottom:1px solid rgba(148,163,184,0.1);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:linear-gradient(135deg,#8b5cf6,#ec4899);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px;">
                            🌐
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:#f8fafc;">Synergy Wholesale</h3>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">Domain registrar API configuration</p>
                        </div>
                    </div>
                    <svg id="synergy-icon" style="width:20px;height:20px;transition:transform 0.3s;color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="synergy-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="synergy_reseller_id" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Reseller ID
                    </label>
                    <input id="synergy_reseller_id"
                           type="text"
                           name="synergy[reseller_id]"
                           value="{{ $settings['synergy']['reseller_id'] ?? '' }}"
                           placeholder="Reseller ID"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="synergy_api_key" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        API Key
                    </label>

                    <div style="display:flex;gap:8px;align-items:center;">
                        <input id="synergy_api_key"
                               type="password"
                               name="synergy[api_key]"
                               autocomplete="new-password"
                               @if(!empty($settings['synergy']['api_key']))
                                   value="********"
                               @else
                                   value=""
                               @endif
                               placeholder="Set or update API key"
                               style="flex:1;padding:8px 10px;border-radius:4px;
                                      border:1px solid #e5e7eb;font-size:14px;">

                        <button type="button"
                                class="btn-accent"
                                style="white-space:nowrap;padding:8px 12px;"
                                onclick="(function(){const input=document.getElementById('synergy_api_key');if(input && input.value==='********'){input.value='';}if(input){input.focus();}})();">
                            Update key
                        </button>
                    </div>

                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        Stored securely; value is not displayed. Click "Update key" to set a new one.
                    </small>
                </div>

                <div style="margin-bottom:0;">
                    <label for="synergy_wsdl_path" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        WSDL path or URL
                    </label>
                    <input id="synergy_wsdl_path"
                           type="text"
                           name="synergy[wsdl_path]"
                           value="{{ $settings['synergy']['wsdl_path'] ?? '' }}"
                           placeholder="WSDL path or URL"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>
                </div>
            </div>

            {{-- HALO PSA SECTION --}}
            <div class="settings-section" style="background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('halo')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:rgba(15,23,42,0.4);border-bottom:1px solid rgba(148,163,184,0.1);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:linear-gradient(135deg,#10b981,#059669);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px;">
                            🔧
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:#f8fafc;">HaloPSA</h3>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">PSA integration settings</p>
                        </div>
                    </div>
                    <svg id="halo-icon" style="width:20px;height:20px;transition:transform 0.3s;color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="halo-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="halo_base_url" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Resource server URL
                    </label>
                    <input id="halo_base_url"
                           type="text"
                           name="halo[base_url]"
                           value="{{ old('halo.base_url', $settings['halo']['base_url'] ?? '') }}"
                           placeholder="https://yourtenant.halopsa.com/api"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        Resource Server URL from Halo &gt; Configuration &gt; Integrations &gt; Halo API.
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="halo_auth_server" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Authorisation server URL
                    </label>
                    <input id="halo_auth_server"
                           type="text"
                           name="halo[auth_server]"
                           value="{{ old('halo.auth_server', $settings['halo']['auth_server'] ?? '') }}"
                           placeholder="https://auth.halopsa.com/auth"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        Authorisation Server URL from Halo API details (optional – leave blank to derive from resource server).
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="halo_tenant" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Tenant
                    </label>
                    <input id="halo_tenant"
                           type="text"
                           name="halo[tenant]"
                           value="{{ old('halo.tenant', $settings['halo']['tenant'] ?? '') }}"
                           placeholder="yourtenant"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        Tenant / account name (if using the hosted HaloPSA cloud).
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="halo_client_id" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Client ID
                    </label>
                    <input id="halo_client_id"
                           type="text"
                           name="halo[client_id]"
                           value="{{ old('halo.client_id', $settings['halo']['client_id'] ?? '') }}"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:0;">
                    <label for="halo_api_key" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        API key (Client Secret)
                    </label>

                    <div style="display:flex;gap:8px;align-items:center;">
                        <input id="halo_api_key"
                               type="password"
                               name="halo[api_key]"
                               autocomplete="new-password"
                               @if(!empty($settings['halo']['api_key']))
                                   value="********"
                               @else
                                   value=""
                               @endif
                               placeholder="Set or update API key"
                               style="flex:1;padding:8px 10px;border-radius:4px;
                                      border:1px solid #e5e7eb;font-size:14px;">

                        <button type="button"
                                class="btn-accent"
                                style="white-space:nowrap;padding:8px 12px;"
                                onclick="(function(){const input=document.getElementById('halo_api_key');if(input && input.value==='********'){input.value='';}if(input){input.focus();}})();">
                            Update key
                        </button>
                    </div>

                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        Stored securely; value is not displayed. Click "Update key" to set a new one.
                    </small>
                </div>
                </div>
            </div>

            {{-- ITGLUE SECTION --}}
            <div class="settings-section" style="background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('itglue')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:rgba(15,23,42,0.4);border-bottom:1px solid rgba(148,163,184,0.1);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px;">
                            📋
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:#f8fafc;">ITGlue</h3>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">Documentation platform integration</p>
                        </div>
                    </div>
                    <svg id="itglue-icon" style="width:20px;height:20px;transition:transform 0.3s;color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="itglue-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="itglue_base_url" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Base URL
                    </label>
                    <input id="itglue_base_url"
                           type="text"
                           name="itglue[base_url]"
                           value="{{ $settings['itglue']['base_url'] ?? '' }}"
                           placeholder="https://api.itglue.com"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:0;">
                    <label for="itglue_api_key" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        API Key
                    </label>
                    <input id="itglue_api_key"
                           type="text"
                           name="itglue[api_key]"
                           value="{{ $settings['itglue']['api_key'] ?? '' }}"
                           placeholder="API Key"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                @php
                    $flexibleAssetTraits = $settings['itglue']['flexible_asset_traits'] ?? [
                        'domain' => 'domain-name',
                        'name_servers' => 'name-servers',
                        'expiry' => 'expiry',
                        'whois' => 'whois',
                        'dns' => 'dns',
                    ];
                @endphp

                <div style="margin-top:16px;">
                    <label for="itglue_flexible_asset_type_id" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Flexible Asset Type ID
                    </label>
                    <input id="itglue_flexible_asset_type_id"
                           type="text"
                           name="itglue[flexible_asset_type_id]"
                           value="{{ $settings['itglue']['flexible_asset_type_id'] ?? '' }}"
                           placeholder="e.g. 4521154692859938"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        Required when syncing domains to flexible assets.
                    </small>
                </div>

                <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">Domain trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][domain]" value="{{ $flexibleAssetTraits['domain'] ?? '' }}" style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:13px;" placeholder="domain-name">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">Name servers trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][name_servers]" value="{{ $flexibleAssetTraits['name_servers'] ?? '' }}" style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:13px;" placeholder="name-servers">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">Expiry trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][expiry]" value="{{ $flexibleAssetTraits['expiry'] ?? '' }}" style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:13px;" placeholder="expiry">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">WHOIS trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][whois]" value="{{ $flexibleAssetTraits['whois'] ?? '' }}" style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:13px;" placeholder="whois">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">DNS trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][dns]" value="{{ $flexibleAssetTraits['dns'] ?? '' }}" style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:13px;" placeholder="dns">
                    </div>
                </div>
                </div>
            </div>

            {{-- SMTP SECTION --}}
            <div class="settings-section" style="background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('smtp')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:rgba(15,23,42,0.4);border-bottom:1px solid rgba(148,163,184,0.1);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:linear-gradient(135deg,#ef4444,#dc2626);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px;">
                            📧
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:#f8fafc;">SMTP</h3>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">Email server configuration</p>
                        </div>
                    </div>
                    <svg id="smtp-icon" style="width:20px;height:20px;transition:transform 0.3s;color:#94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="smtp-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="smtp_host" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        SMTP Host
                    </label>
                    <input id="smtp_host"
                           type="text"
                           name="smtp[host]"
                           value="{{ $settings['smtp']['host'] ?? '' }}"
                           placeholder="smtp.example.com"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_port" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Port
                    </label>
                    <input id="smtp_port"
                           type="number"
                           name="smtp[port]"
                           value="{{ $settings['smtp']['port'] ?? 587 }}"
                           placeholder="587"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:#9ca3af;">
                        Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_encryption" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Encryption
                    </label>
                    <select id="smtp_encryption"
                            name="smtp[encryption]"
                            style="width:100%;padding:8px 10px;border-radius:4px;
                                   border:1px solid #e5e7eb;font-size:14px;background:#0b1120;color:#f8fafc;">
                        <option value="">None</option>
                        <option value="tls" {{ ($settings['smtp']['encryption'] ?? '') === 'tls' ? 'selected' : '' }}>TLS</option>
                        <option value="ssl" {{ ($settings['smtp']['encryption'] ?? '') === 'ssl' ? 'selected' : '' }}>SSL</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_username" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Username
                    </label>
                    <input id="smtp_username"
                           type="text"
                           name="smtp[username]"
                           value="{{ $settings['smtp']['username'] ?? '' }}"
                           placeholder="SMTP username (if required)"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_password" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        Password
                    </label>
                    <input id="smtp_password"
                           type="password"
                           name="smtp[password]"
                           value="{{ $settings['smtp']['password'] ?? '' }}"
                           placeholder="SMTP password (if required)"
                           autocomplete="new-password"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_from" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        From Address
                    </label>
                    <input id="smtp_from"
                           type="email"
                           name="smtp[from]"
                           value="{{ $settings['smtp']['from'] ?? '' }}"
                           placeholder="noreply@yourdomain.com"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:0;">
                    <label for="smtp_from_name" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        From Name
                    </label>
                    <input id="smtp_from_name"
                           type="text"
                           name="smtp[from_name]"
                           value="{{ $settings['smtp']['from_name'] ?? 'DomainDash' }}"
                           placeholder="DomainDash"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>
                </div>
            </div>

            {{-- Action buttons --}}
            <div style="padding:20px;background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;">
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <button type="submit" class="btn-accent" style="padding:12px 32px;font-size:15px;font-weight:600;">
                        💾 Save All Settings
                    </button>
                    <button type="button" onclick="openHaloSyncModal()" class="btn-accent" style="padding:12px 32px;font-size:15px;font-weight:600;background:linear-gradient(135deg,#10b981,#059669);">
                        🔄 Sync with Halo
                    </button>
                    <button type="button" onclick="openItGlueSyncModal()" class="btn-accent" style="padding:12px 32px;font-size:15px;font-weight:600;background:linear-gradient(135deg,#f59e0b,#d97706);">
                        🔄 Sync IT Glue
                    </button>
                </div>
            </div>
        </form>

        {{-- SMTP test card --}}
        <div style="background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;margin-top:16px;overflow:hidden;">
            <div style="padding:16px 20px;background:rgba(15,23,42,0.4);border-bottom:1px solid rgba(148,163,184,0.1);">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;background:linear-gradient(135deg,#06b6d4,#0891b2);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px;">
                        ✉️
                    </div>
                    <div>
                        <h3 style="font-size:16px;font-weight:600;margin:0;color:#f8fafc;">SMTP Test</h3>
                        <p style="font-size:13px;color:#94a3b8;margin:0;">Send a test email to verify your configuration</p>
                    </div>
                </div>
            </div>
            <div style="padding:20px 24px;">
                <form method="POST" action="{{ route('admin.settings.smtp-test') }}">
                    @csrf

                    <div style="margin-bottom:12px;">
                        <label for="smtp_test_to" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                            Test recipient
                        </label>
                        <input id="smtp_test_to"
                               type="email"
                               name="to"
                               placeholder="test@yourdomain.com"
                               required
                               style="width:100%;padding:8px 10px;border-radius:4px;
                                      border:1px solid #e5e7eb;font-size:14px;">
                    </div>

                    <button type="submit" class="btn-accent" style="padding:10px 20px;">
                        Send Test Email
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Halo Sync Modal --}}
    <div id="haloSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:rgba(15,23,42,0.95);border:1px solid rgba(148,163,184,0.2);border-radius:12px;max-width:1200px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:20px 24px;border-bottom:1px solid rgba(148,163,184,0.1);display:flex;justify-content:space-between;align-items:center;">
                <h2 style="font-size:20px;font-weight:700;margin:0;color:#f8fafc;">🔄 Sync with HaloPSA</h2>
                <button onclick="closeHaloSyncModal()" style="background:none;border:none;color:#94a3b8;font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                <div style="display:flex;gap:16px;margin-bottom:24px;">
                    <button onclick="showHaloClientSync()" id="haloClientsBtn" class="sync-option-btn" style="flex:1;padding:16px;background:linear-gradient(135deg,#10b981,#059669);border:2px solid #10b981;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;transition:all 0.2s;">
                        👥 Sync Clients
                    </button>
                    <button onclick="showHaloDomainSync()" id="haloDomainsBtn" class="sync-option-btn" style="flex:1;padding:16px;background:rgba(15,23,42,0.4);border:2px solid rgba(148,163,184,0.2);border-radius:8px;color:#94a3b8;font-weight:600;cursor:pointer;transition:all 0.2s;">
                        🌐 Sync Domains
                    </button>
                </div>

                <div id="haloClientSyncContent" style="display:block;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:#f8fafc;margin:0;">Client Mapping</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadHaloClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                🔄 Refresh List
                            </button>
                            <button onclick="syncHaloClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                ✓ Sync Selected
                            </button>
                        </div>
                    </div>
                    <div id="haloClientList" style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.1);border-radius:8px;padding:16px;">
                        <div style="text-align:center;color:#94a3b8;padding:40px;">
                            Click "Refresh List" to load clients from HaloPSA
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeHaloSyncModal()" style="padding:10px 20px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:6px;color:#ef4444;cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>

                <div id="haloDomainSyncContent" style="display:none;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:#f8fafc;margin:0;">Domain Assets</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadHaloDomains()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                🔄 Refresh List
                            </button>
                            <button onclick="syncHaloDomains()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                ✓ Sync Selected
                            </button>
                        </div>
                    </div>
                    <div id="haloDomainList" style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.1);border-radius:8px;padding:16px;">
                        <div style="text-align:center;color:#94a3b8;padding:40px;">
                            Click "Refresh List" to load domains from DomainDash
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeHaloSyncModal()" style="padding:10px 20px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:6px;color:#ef4444;cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- IT Glue Sync Modal --}}
    <div id="itglueSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:rgba(15,23,42,0.95);border:1px solid rgba(148,163,184,0.2);border-radius:12px;max-width:1200px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:20px 24px;border-bottom:1px solid rgba(148,163,184,0.1);display:flex;justify-content:space-between;align-items:center;">
                <h2 style="font-size:20px;font-weight:700;margin:0;color:#f8fafc;">🔄 Sync with IT Glue</h2>
                <button onclick="closeItGlueSyncModal()" style="background:none;border:none;color:#94a3b8;font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                <div style="display:flex;gap:16px;margin-bottom:24px;">
                    <button onclick="showItGlueClientSync()" id="itglueClientsBtn" class="sync-option-btn" style="flex:1;padding:16px;background:linear-gradient(135deg,#f59e0b,#d97706);border:2px solid #f59e0b;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;transition:all 0.2s;">
                        👥 Sync Clients
                    </button>
                    <button onclick="showItGlueConfigSync()" id="itglueConfigBtn" class="sync-option-btn" style="flex:1;padding:16px;background:rgba(15,23,42,0.4);border:2px solid rgba(148,163,184,0.2);border-radius:8px;color:#94a3b8;font-weight:600;cursor:pointer;transition:all 0.2s;">
                        ⚙️ Sync Configurations
                    </button>
                </div>

                <div id="itglueClientSyncContent" style="display:block;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:#f8fafc;margin:0;">Organization Mapping</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadItGlueClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                🔄 Refresh List
                            </button>
                            <button onclick="syncItGlueClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                ✓ Save Mappings
                            </button>
                        </div>
                    </div>
                    <div id="itglueClientList" style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.1);border-radius:8px;padding:16px;">
                        <div style="text-align:center;color:#94a3b8;padding:40px;">
                            Click "Refresh List" to load clients from DomainDash
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeItGlueSyncModal()" style="padding:10px 20px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:6px;color:#ef4444;cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>

                <div id="itglueConfigSyncContent" style="display:none;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:#f8fafc;margin:0;">Configuration Items</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadItGlueConfigs()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                🔄 Refresh List
                            </button>
                            <button onclick="syncItGlueConfigs()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                ✓ Sync Selected
                            </button>
                        </div>
                    </div>
                    <div id="itglueConfigList" style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.1);border-radius:8px;padding:16px;">
                        <div style="text-align:center;color:#94a3b8;padding:40px;">
                            Click "Refresh List" to load configuration items
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeItGlueSyncModal()" style="padding:10px 20px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:6px;color:#ef4444;cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSection(sectionName) {
            const content = document.getElementById(sectionName + '-content');
            const icon = document.getElementById(sectionName + '-icon');

            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';
            } else {
                content.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
            }
        }

        // Add hover effect to section headers
        document.addEventListener('DOMContentLoaded', function() {
            const headers = document.querySelectorAll('.settings-header');
            headers.forEach(header => {
                header.addEventListener('mouseenter', function() {
                    this.style.background = 'rgba(15,23,42,0.6)';
                });
                header.addEventListener('mouseleave', function() {
                    this.style.background = 'rgba(15,23,42,0.4)';
                });
            });
        });

        // Halo Sync Modal Functions
        function openHaloSyncModal() {
            document.getElementById('haloSyncModal').style.display = 'flex';
        }

        function closeHaloSyncModal() {
            document.getElementById('haloSyncModal').style.display = 'none';
        }

        function showHaloClientSync() {
            document.getElementById('haloClientSyncContent').style.display = 'block';
            document.getElementById('haloDomainSyncContent').style.display = 'none';
            document.getElementById('haloClientsBtn').style.background = 'linear-gradient(135deg,#10b981,#059669)';
            document.getElementById('haloClientsBtn').style.borderColor = '#10b981';
            document.getElementById('haloClientsBtn').style.color = '#fff';
            document.getElementById('haloDomainsBtn').style.background = 'rgba(15,23,42,0.4)';
            document.getElementById('haloDomainsBtn').style.borderColor = 'rgba(148,163,184,0.2)';
            document.getElementById('haloDomainsBtn').style.color = '#94a3b8';
        }

        function showHaloDomainSync() {
            document.getElementById('haloClientSyncContent').style.display = 'none';
            document.getElementById('haloDomainSyncContent').style.display = 'block';
            document.getElementById('haloDomainsBtn').style.background = 'linear-gradient(135deg,#10b981,#059669)';
            document.getElementById('haloDomainsBtn').style.borderColor = '#10b981';
            document.getElementById('haloDomainsBtn').style.color = '#fff';
            document.getElementById('haloClientsBtn').style.background = 'rgba(15,23,42,0.4)';
            document.getElementById('haloClientsBtn').style.borderColor = 'rgba(148,163,184,0.2)';
            document.getElementById('haloClientsBtn').style.color = '#94a3b8';
        }

        async function loadHaloClients() {
            const listEl = document.getElementById('haloClientList');
            listEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;"><div style="font-size:32px;margin-bottom:12px;">⏳</div>Loading clients from HaloPSA...</div>';

            try {
                const response = await fetch('/admin/sync/halo/clients');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">${data.error}</div>`;
                    return;
                }

                renderHaloClientList(data.clients);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">Failed to load clients: ${error.message}</div>`;
            }
        }

        function renderHaloClientList(clients) {
            const listEl = document.getElementById('haloClientList');

            let html = `
                <div style="margin-bottom:16px;padding:12px;background:rgba(15,23,42,0.6);border-radius:6px;display:grid;grid-template-columns:40px 1fr 1fr 150px 80px;gap:12px;align-items:center;font-weight:600;color:#94a3b8;font-size:13px;">
                    <input type="checkbox" id="selectAllHaloClients" onchange="toggleAllHaloClients(this)" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                    <div>HaloPSA Client</div>
                    <div>DomainDash Client</div>
                    <div style="text-align:center;">Updated</div>
                    <div style="text-align:center;">Action</div>
                </div>
            `;

            clients.forEach((client, index) => {
                html += `
                    <div style="padding:12px;background:rgba(15,23,42,0.3);border-radius:6px;margin-bottom:8px;display:grid;grid-template-columns:40px 1fr 1fr 150px 80px;gap:12px;align-items:center;">
                        <input type="checkbox" class="halo-client-checkbox" data-client-id="${client.halo_id}" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                        <div style="color:#f8fafc;">${client.halo_name}</div>
                        <select class="halo-client-mapping" data-halo-id="${client.halo_id}" style="padding:8px;background:#0b1120;border:1px solid rgba(148,163,184,0.2);border-radius:4px;color:#f8fafc;width:100%;">
                            <option value="">-- Select Client --</option>
                            ${client.suggestions.map(s => `<option value="${s.id}" ${s.id === client.mapped_id ? 'selected' : ''}>${s.name}</option>`).join('')}
                        </select>
                        <div style="text-align:center;color:#94a3b8;font-size:13px;">${client.updated || 'N/A'}</div>
                        <div style="text-align:center;">
                            ${client.mapped_id ? '' : '<button onclick="createClientFromHalo(\'' + client.halo_id + '\')" style="padding:6px 12px;background:linear-gradient(135deg,#10b981,#059669);border:none;border-radius:4px;color:#fff;cursor:pointer;font-weight:600;">+</button>'}
                        </div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }

        function toggleAllHaloClients(checkbox) {
            const checkboxes = document.querySelectorAll('.halo-client-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }

        async function syncHaloClients() {
            const selectedClients = [];
            document.querySelectorAll('.halo-client-checkbox:checked').forEach(checkbox => {
                const haloId = checkbox.dataset.clientId;
                const mappingSelect = document.querySelector(`.halo-client-mapping[data-halo-id="${haloId}"]`);
                const dashClientId = mappingSelect.value;

                if (dashClientId) {
                    selectedClients.push({
                        halo_id: haloId,
                        dash_client_id: dashClientId
                    });
                }
            });

            if (selectedClients.length === 0) {
                alert('Please select at least one client to sync');
                return;
            }

            try {
                const response = await fetch('/admin/sync/halo/clients/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ clients: selectedClients })
                });

                const data = await response.json();

                if (data.success) {
                    alert(`Successfully synced ${data.synced_count} client(s)`);
                    closeHaloSyncModal();
                    location.reload();
                } else {
                    alert('Sync failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Sync failed: ' + error.message);
            }
        }

        async function loadHaloDomains() {
            const listEl = document.getElementById('haloDomainList');
            listEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;"><div style="font-size:32px;margin-bottom:12px;">⏳</div>Loading domains from DomainDash...</div>';

            try {
                const response = await fetch('/admin/sync/halo/domains');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">${data.error}</div>`;
                    return;
                }

                renderHaloDomainList(data.domains);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">Failed to load domains: ${error.message}</div>`;
            }
        }

        function renderHaloDomainList(domains) {
            const listEl = document.getElementById('haloDomainList');

            let html = `
                <div style="margin-bottom:16px;padding:12px;background:rgba(15,23,42,0.6);border-radius:6px;display:grid;grid-template-columns:40px 1fr 1fr 150px 100px;gap:12px;align-items:center;font-weight:600;color:#94a3b8;font-size:13px;">
                    <input type="checkbox" id="selectAllHaloDomains" onchange="toggleAllHaloDomains(this)" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                    <div>Domain Name</div>
                    <div>Client</div>
                    <div style="text-align:center;">Expiry</div>
                    <div style="text-align:center;">Status</div>
                </div>
            `;

            domains.forEach(domain => {
                const statusColor = domain.exists_in_halo ? '#10b981' : '#94a3b8';
                const statusText = domain.exists_in_halo ? 'Exists' : 'Will Create';

                html += `
                    <div style="padding:12px;background:rgba(15,23,42,0.3);border-radius:6px;margin-bottom:8px;display:grid;grid-template-columns:40px 1fr 1fr 150px 100px;gap:12px;align-items:center;">
                        <input type="checkbox" class="halo-domain-checkbox" data-domain-id="${domain.id}" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                        <div style="color:#f8fafc;">${domain.name}</div>
                        <div style="color:#94a3b8;font-size:13px;">${domain.client || 'No Client'}</div>
                        <div style="text-align:center;color:#94a3b8;font-size:13px;">${domain.expiry || 'N/A'}</div>
                        <div style="text-align:center;color:${statusColor};font-size:13px;">${statusText}</div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }

        function toggleAllHaloDomains(checkbox) {
            const checkboxes = document.querySelectorAll('.halo-domain-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }

        async function syncHaloDomains() {
            const selectedDomains = [];
            document.querySelectorAll('.halo-domain-checkbox:checked').forEach(checkbox => {
                selectedDomains.push(checkbox.dataset.domainId);
            });

            if (selectedDomains.length === 0) {
                alert('Please select at least one domain to sync');
                return;
            }

            try {
                const response = await fetch('/admin/sync/halo/domains/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ domain_ids: selectedDomains })
                });

                const data = await response.json();

                if (data.success) {
                    let message = `Successfully synced ${data.synced_count} domain(s)`;

                    if (data.warnings && data.warnings.length > 0) {
                        message += '\n\nWarnings:\n' + data.warnings.join('\n');
                    }

                    alert(message);
                    closeHaloSyncModal();
                    location.reload();
                } else {
                    alert('Sync failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Sync failed: ' + error.message);
            }
        }

        // IT Glue Sync Modal Functions
        function openItGlueSyncModal() {
            document.getElementById('itglueSyncModal').style.display = 'flex';
        }

        function closeItGlueSyncModal() {
            document.getElementById('itglueSyncModal').style.display = 'none';
        }

        function showItGlueClientSync() {
            document.getElementById('itglueClientSyncContent').style.display = 'block';
            document.getElementById('itglueConfigSyncContent').style.display = 'none';
            document.getElementById('itglueClientsBtn').style.background = 'linear-gradient(135deg,#f59e0b,#d97706)';
            document.getElementById('itglueClientsBtn').style.borderColor = '#f59e0b';
            document.getElementById('itglueClientsBtn').style.color = '#fff';
            document.getElementById('itglueConfigBtn').style.background = 'rgba(15,23,42,0.4)';
            document.getElementById('itglueConfigBtn').style.borderColor = 'rgba(148,163,184,0.2)';
            document.getElementById('itglueConfigBtn').style.color = '#94a3b8';
        }

        function showItGlueConfigSync() {
            document.getElementById('itglueClientSyncContent').style.display = 'none';
            document.getElementById('itglueConfigSyncContent').style.display = 'block';
            document.getElementById('itglueConfigBtn').style.background = 'linear-gradient(135deg,#f59e0b,#d97706)';
            document.getElementById('itglueConfigBtn').style.borderColor = '#f59e0b';
            document.getElementById('itglueConfigBtn').style.color = '#fff';
            document.getElementById('itglueClientsBtn').style.background = 'rgba(15,23,42,0.4)';
            document.getElementById('itglueClientsBtn').style.borderColor = 'rgba(148,163,184,0.2)';
            document.getElementById('itglueClientsBtn').style.color = '#94a3b8';
        }

        async function loadItGlueClients() {
            const listEl = document.getElementById('itglueClientList');
            listEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;"><div style="font-size:32px;margin-bottom:12px;">⏳</div>Loading clients...</div>';

            try {
                const response = await fetch('/admin/sync/itglue/clients');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">${data.error}</div>`;
                    return;
                }

                renderItGlueClientList(data.clients);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">Failed to load clients: ${error.message}</div>`;
            }
        }

        function renderItGlueClientList(clients) {
            const listEl = document.getElementById('itglueClientList');

            let html = `
                <div style="margin-bottom:16px;padding:12px;background:rgba(15,23,42,0.6);border-radius:6px;display:grid;grid-template-columns:1fr 1fr 120px;gap:12px;align-items:center;font-weight:600;color:#94a3b8;font-size:13px;">
                    <div>DomainDash Client</div>
                    <div>IT Glue Organization</div>
                    <div style="text-align:center;">Action</div>
                </div>
            `;

            clients.forEach(client => {
                html += `
                    <div style="padding:12px;background:rgba(15,23,42,0.3);border-radius:6px;margin-bottom:8px;display:grid;grid-template-columns:1fr 1fr 120px;gap:12px;align-items:center;">
                        <div style="color:#f8fafc;">${client.dash_name}</div>
                        <select class="itglue-client-mapping" data-dash-id="${client.dash_id}" style="padding:8px;background:#0b1120;border:1px solid rgba(148,163,184,0.2);border-radius:4px;color:#f8fafc;width:100%;">
                            <option value="">-- Select Organization --</option>
                            ${client.organizations.map(org => `<option value="${org.id}" ${org.id === client.mapped_id ? 'selected' : ''}>${org.name}</option>`).join('')}
                        </select>
                        <div style="text-align:center;">
                            ${!client.mapped_id ? '<button onclick="suggestItGlueOrg(\'' + client.dash_id + '\')" style="padding:6px 12px;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:4px;color:#fff;cursor:pointer;font-weight:600;font-size:13px;">Suggest</button>' : '<span style="color:#10b981;font-size:13px;">✓ Mapped</span>'}
                        </div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }

        async function suggestItGlueOrg(dashClientId) {
            try {
                const response = await fetch(`/admin/sync/itglue/suggest/${dashClientId}`);
                const data = await response.json();

                if (data.suggested_org_id) {
                    const select = document.querySelector(`.itglue-client-mapping[data-dash-id="${dashClientId}"]`);
                    select.value = data.suggested_org_id;
                } else {
                    alert('No matching organization found');
                }
            } catch (error) {
                alert('Failed to suggest organization: ' + error.message);
            }
        }

        async function syncItGlueClients() {
            const mappings = [];
            document.querySelectorAll('.itglue-client-mapping').forEach(select => {
                const dashId = select.dataset.dashId;
                const orgId = select.value;

                if (orgId) {
                    mappings.push({
                        dash_client_id: dashId,
                        itglue_org_id: orgId
                    });
                }
            });

            if (mappings.length === 0) {
                alert('Please map at least one client');
                return;
            }

            try {
                const response = await fetch('/admin/sync/itglue/clients/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ mappings: mappings })
                });

                const data = await response.json();

                if (data.success) {
                    alert(`Successfully saved ${data.mapped_count} mapping(s)`);
                    closeItGlueSyncModal();
                    location.reload();
                } else {
                    alert('Save failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Save failed: ' + error.message);
            }
        }

        async function loadItGlueConfigs() {
            const listEl = document.getElementById('itglueConfigList');
            listEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;"><div style="font-size:32px;margin-bottom:12px;">⏳</div>Loading configuration items...</div>';

            try {
                const response = await fetch('/admin/sync/itglue/configurations');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">${data.error}</div>`;
                    return;
                }

                renderItGlueConfigList(data.items);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:#ef4444;padding:40px;">Failed to load items: ${error.message}</div>`;
            }
        }

        function renderItGlueConfigList(items) {
            const listEl = document.getElementById('itglueConfigList');

            let html = `
                <div style="margin-bottom:16px;padding:12px;background:rgba(15,23,42,0.6);border-radius:6px;display:grid;grid-template-columns:40px 1fr 1fr 120px 100px;gap:12px;align-items:center;font-weight:600;color:#94a3b8;font-size:13px;">
                    <input type="checkbox" id="selectAllItGlueConfigs" onchange="toggleAllItGlueConfigs(this)" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                    <div>Name</div>
                    <div>Client</div>
                    <div>Type</div>
                    <div style="text-align:center;">Status</div>
                </div>
            `;

            items.forEach(item => {
                const statusColor = item.exists_in_itglue ? '#10b981' : '#94a3b8';
                const statusText = item.exists_in_itglue ? 'Exists' : 'Will Create';

                html += `
                    <div style="padding:12px;background:rgba(15,23,42,0.3);border-radius:6px;margin-bottom:8px;display:grid;grid-template-columns:40px 1fr 1fr 120px 100px;gap:12px;align-items:center;">
                        <input type="checkbox" class="itglue-config-checkbox" data-item-id="${item.id}" data-item-type="${item.type}" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                        <div style="color:#f8fafc;">${item.name}</div>
                        <div style="color:#94a3b8;font-size:13px;">${item.client || 'No Client'}</div>
                        <div style="color:#94a3b8;font-size:13px;">${item.type}</div>
                        <div style="text-align:center;color:${statusColor};font-size:13px;">${statusText}</div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }

        function toggleAllItGlueConfigs(checkbox) {
            const checkboxes = document.querySelectorAll('.itglue-config-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }

        async function syncItGlueConfigs() {
            const selectedItems = [];
            document.querySelectorAll('.itglue-config-checkbox:checked').forEach(checkbox => {
                selectedItems.push({
                    id: checkbox.dataset.itemId,
                    type: checkbox.dataset.itemType
                });
            });

            if (selectedItems.length === 0) {
                alert('Please select at least one item to sync');
                return;
            }

            showItGlueSyncProgress();

            try {
                const response = await fetch('/admin/sync/itglue/configurations/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ items: selectedItems })
                });

                const data = await response.json();

                if (data.success) {
                    alert(`Successfully synced ${data.synced_count} item(s)`);
                    closeItGlueSyncModal();
                    location.reload();
                } else {
                    alert('Sync failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Sync failed: ' + error.message);
            } finally {
                hideItGlueSyncProgress();
            }
        }

        function showItGlueSyncProgress() {
            let overlay = document.getElementById('itglueSyncProgress');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'itglueSyncProgress';
                overlay.style.position = 'fixed';
                overlay.style.top = '0';
                overlay.style.left = '0';
                overlay.style.width = '100%';
                overlay.style.height = '100%';
                overlay.style.background = 'rgba(0,0,0,0.65)';
                overlay.style.zIndex = '10000';
                overlay.style.display = 'flex';
                overlay.style.alignItems = 'center';
                overlay.style.justifyContent = 'center';
                overlay.innerHTML = `
                    <div style="background:rgba(15,23,42,0.95);border:1px solid rgba(148,163,184,0.3);border-radius:12px;padding:24px;min-width:320px;text-align:center;color:#e2e8f0;box-shadow:0 20px 50px rgba(0,0,0,0.5);">
                        <div style="font-size:18px;font-weight:700;margin-bottom:12px;">Syncing to IT Glue…</div>
                        <div style="width:100%;background:rgba(148,163,184,0.2);border-radius:9999px;overflow:hidden;">
                            <div id="itglueProgressBar" style="width:35%;height:10px;background:linear-gradient(135deg,#f59e0b,#d97706);animation:glow 1.2s ease-in-out infinite alternate;"></div>
                        </div>
                        <div style="margin-top:10px;font-size:13px;color:#cbd5e1;">Please keep this tab open while we sync.</div>
                    </div>
                    <style>
                        @keyframes glow {
                            from { width: 20%; opacity: 0.7; }
                            to   { width: 80%; opacity: 1; }
                        }
                    </style>
                `;
                document.body.appendChild(overlay);
            }
            overlay.style.display = 'flex';
        }

        function hideItGlueSyncProgress() {
            const overlay = document.getElementById('itglueSyncProgress');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }
    </script>
@endsection
