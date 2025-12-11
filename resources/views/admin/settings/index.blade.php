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
                <div id="branding-content" class="settings-content" style="padding:20px 24px;">

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
                        SMTP host
                    </label>
                    <input id="smtp_host"
                           type="text"
                           name="smtp[host]"
                           value="{{ $settings['smtp']['host'] ?? '' }}"
                           placeholder="SMTP host"
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
                           placeholder="Port"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:0;">
                    <label for="smtp_from" style="display:block;font-size:14px;margin-bottom:4px;color:#e2e8f0;font-weight:500;">
                        From address
                    </label>
                    <input id="smtp_from"
                           type="email"
                           name="smtp[from]"
                           value="{{ $settings['smtp']['from'] ?? '' }}"
                           placeholder="from@yourdomain.com"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>
                </div>
            </div>

            {{-- Save button --}}
            <div style="padding:20px;background:rgba(15,23,42,0.6);border:1px solid rgba(148,163,184,0.1);border-radius:12px;text-align:center;">
                <button type="submit" class="btn-accent" style="padding:12px 32px;font-size:15px;font-weight:600;">
                    💾 Save All Settings
                </button>
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
    </script>
@endsection
