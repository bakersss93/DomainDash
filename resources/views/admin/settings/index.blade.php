@extends('layouts.app')

@section('content')
    @include('admin.sync._live')
    <div class="dd-settings-page">
        @include('admin.sync._worker-banner')

        {{-- Title --}}
        <x-ui.page-header title="Settings" subtitle="Configure your DomainDash installation and integrations." />

        {{-- Success Message --}}
        {{-- Error Messages --}}
        @if($errors->any())
            <x-ui.alert variant="danger" class="mb-4"><strong>⚠</strong>
                <ul style="margin:4px 0 0 20px;padding:0;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul></x-ui.alert>
        @endif

        {{-- PLATFORM HTTPS (own page: it has its own forms, which cannot nest inside the settings form) --}}
        <x-ui.card title="Platform HTTPS" description="Let's Encrypt (DNS-01 via Synergy) or uploaded certificate, force HTTPS, renewal status." class="mb-4">
            <x-ui.button variant="secondary" :href="route('admin.https')">Manage Platform HTTPS</x-ui.button>
        </x-ui.card>

        <form method="POST"
              action="{{ route('admin.settings.update') }}"
              enctype="multipart/form-data">
            @csrf

            {{-- BRANDING SECTION --}}
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('branding')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">Branding</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">Customize colors and logo</p>
                        </div>
                    </div>
                    <svg id="branding-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="branding-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px;margin-bottom:16px;">

                    {{-- LIGHT MODE COLOURS --}}
                    <div>
                        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted,#64748b);margin:0 0 12px 0;">Light Mode</h4>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Primary Color</label>
                            <input type="color"
                                   name="branding[primary]"
                                   value="{{ $settings['branding']['primary'] ?? '#26272c' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Accent Color</label>
                            <input type="color"
                                   name="branding[accent]"
                                   value="{{ $settings['branding']['accent'] ?? '#2f7d4b' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Background Color</label>
                            <input type="color"
                                   name="branding[bg]"
                                   value="{{ $settings['branding']['bg'] ?? '#f5f6f8' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Text Color</label>
                            <input type="color"
                                   name="branding[text]"
                                   value="{{ $settings['branding']['text'] ?? '#1b1d22' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:0;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Button Text Color</label>
                            <input type="color"
                                   name="branding[button_text]"
                                   value="{{ $settings['branding']['button_text'] ?? '#ffffff' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                            <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">Text colour used on accent-coloured buttons.</small>
                        </div>
                    </div>

                    {{-- DARK MODE COLOURS --}}
                    <div>
                        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted,#64748b);margin:0 0 12px 0;">Dark Mode</h4>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Primary Color</label>
                            <input type="color"
                                   name="branding[primary_dark]"
                                   value="{{ $settings['branding']['primary_dark'] ?? '#15161a' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Accent Color</label>
                            <input type="color"
                                   name="branding[accent_dark]"
                                   value="{{ $settings['branding']['accent_dark'] ?? '#86b57a' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Background Color</label>
                            <input type="color"
                                   name="branding[bg_dark]"
                                   value="{{ $settings['branding']['bg_dark'] ?? '#101114' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Text Color</label>
                            <input type="color"
                                   name="branding[text_dark]"
                                   value="{{ $settings['branding']['text_dark'] ?? '#eceef1' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                        </div>

                        <div style="margin-bottom:0;">
                            <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Button Text Color</label>
                            <input type="color"
                                   name="branding[button_text_dark]"
                                   value="{{ $settings['branding']['button_text_dark'] ?? '#0f1a0f' }}"
                                   style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                            <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">Text colour used on accent-coloured buttons in dark mode.</small>
                        </div>
                    </div>

                </div>

                <div style="margin-bottom:0;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Logo (PNG, up to ~200px wide)
                    </label>

                    @if (!empty($settings['branding']['logo']))
                        <div style="margin-bottom:8px;">
                            <strong style="font-size:14px;color:var(--text,#1e293b);">Current logo:</strong><br>
                            <img src="{{ Storage::url($settings['branding']['logo']) }}"
                                 alt="Current logo"
                                 style="max-height:80px;max-width:200px;border-radius:4px;margin-top:8px;">
                        </div>
                    @endif

                    <input type="file"
                           name="branding_logo"
                           accept="image/png,image/jpeg,image/svg+xml"
                           class="dd-input"
                           style="display:block;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        PNG preferred, 200px wide, height auto-scales.
                    </small>
                </div>
                </div>
            </div>

            {{-- SYNERGY WHOLESALE SECTION --}}
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('synergy')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">Synergy Wholesale</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">Domain registrar API configuration</p>
                        </div>
                    </div>
                    <svg id="synergy-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="synergy-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="synergy_reseller_id" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Reseller ID
                    </label>
                    <input id="synergy_reseller_id"
                           type="text"
                           name="synergy[reseller_id]"
                           value="{{ $settings['synergy']['reseller_id'] ?? '' }}"
                           placeholder="Reseller ID"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="synergy_api_key" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
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
                               style="flex:1;">

                        <button type="button"
                                class="btn-accent"
                                style="white-space:nowrap;padding:8px 12px;"
                                onclick="(function(){const input=document.getElementById('synergy_api_key');if(input && input.value==='********'){input.value='';}if(input){input.focus();}})();">
                            Update key
                        </button>
                    </div>

                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Stored securely; value is not displayed. Click "Update key" to set a new one.
                    </small>
                </div>

                <div style="margin-bottom:0;">
                    <label for="synergy_wsdl_path" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        WSDL path or URL
                    </label>
                    <input id="synergy_wsdl_path"
                           type="text"
                           name="synergy[wsdl_path]"
                           value="{{ $settings['synergy']['wsdl_path'] ?? '' }}"
                           placeholder="WSDL path or URL"
                           style="width:100%;">
                </div>
                </div>
            </div>

            {{-- VOCUS WHOLESALE SECTION --}}
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('vocus')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">Vocus Wholesale</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">NBN internet service management API</p>
                        </div>
                    </div>
                    <svg id="vocus-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="vocus-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="vocus_access_key" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Access Key
                    </label>
                    <input id="vocus_access_key"
                           type="text"
                           name="vocus[access_key]"
                           value="{{ $settings['vocus']['access_key'] ?? '' }}"
                           placeholder="Provided by Vocus during onboarding"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="vocus_alias_key" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Alias Key <span style="font-weight:400;color:var(--dd-text-soft);">(optional)</span>
                    </label>
                    <input id="vocus_alias_key"
                           type="text"
                           name="vocus[alias_key]"
                           value="{{ $settings['vocus']['alias_key'] ?? '' }}"
                           placeholder="Optional secondary access key"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="vocus_wsdl_url" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        SOAP Endpoint URL
                    </label>
                    <input id="vocus_wsdl_url"
                           type="url"
                           name="vocus[wsdl_url]"
                           value="{{ $settings['vocus']['wsdl_url'] ?? 'https://wsm.webservice.m2.com.au/WholesaleServiceManagement' }}"
                           placeholder="https://wsm.webservice.m2.com.au/WholesaleServiceManagement"
                           style="width:100%;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Change to the sandbox URL during testing. Leave blank to use the production default.
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="vocus_login_url" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Certificate Login URL
                    </label>
                    <input id="vocus_login_url"
                           type="url"
                           name="vocus[login_url]"
                           value="{{ $settings['vocus']['login_url'] ?? 'https://wsm.webservice.m2.com.au:9443/login/' }}"
                           placeholder="https://wsm.webservice.m2.com.au:9443/login/"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="vocus_cert" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Client Certificate (.p12 / .pfx / .keystore)
                    </label>
                    @if(!empty($settings['vocus']['cert_path']))
                        <p style="font-size:13px;color:var(--dd-status-success-fg);margin:0 0 6px;">
                            <svg style="display:inline;width:14px;height:14px;vertical-align:-2px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Certificate on file. Upload a new file to replace it.
                        </p>
                    @endif
                    <input id="vocus_cert"
                           type="file"
                           name="vocus_cert"
                           accept=".p12,.pfx,.keystore"
                           style="width:100%;padding:6px 0;font-size:14px;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Vocus issues a new certificate annually. Upload the PKCS#12 keystore file they provide (.p12, .pfx, or .keystore).
                    </small>
                </div>

                <div style="margin-bottom:0;">
                    <label for="vocus_cert_password" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Certificate Password
                    </label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input id="vocus_cert_password"
                               type="password"
                               name="vocus[cert_password]"
                               autocomplete="new-password"
                               @if(!empty($settings['vocus']['cert_password']))
                                   value="********"
                               @else
                                   value=""
                               @endif
                               placeholder="Set or update certificate password"
                               style="flex:1;">
                        <button type="button"
                                class="btn-accent"
                                style="white-space:nowrap;padding:8px 12px;"
                                onclick="(function(){const input=document.getElementById('vocus_cert_password');if(input && input.value==='********'){input.value='';}if(input){input.focus();}})();">
                            Update
                        </button>
                    </div>
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Stored securely; value is not displayed.
                    </small>
                </div>

                </div>
            </div>

            {{-- HALO PSA SECTION --}}
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('halo')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">HaloPSA</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">PSA integration settings</p>
                        </div>
                    </div>
                    <svg id="halo-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="halo-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="halo_base_url" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Resource server URL
                    </label>
                    <input id="halo_base_url"
                           type="text"
                           name="halo[base_url]"
                           value="{{ old('halo.base_url', $settings['halo']['base_url'] ?? '') }}"
                           placeholder="https://yourtenant.halopsa.com/api"
                           style="width:100%;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Resource Server URL from Halo &gt; Configuration &gt; Integrations &gt; Halo API.
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="halo_auth_server" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Authorisation server URL
                    </label>
                    <input id="halo_auth_server"
                           type="text"
                           name="halo[auth_server]"
                           value="{{ old('halo.auth_server', $settings['halo']['auth_server'] ?? '') }}"
                           placeholder="https://auth.halopsa.com/auth"
                           style="width:100%;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Authorisation Server URL from Halo API details (optional – leave blank to derive from resource server).
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="halo_tenant" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Tenant
                    </label>
                    <input id="halo_tenant"
                           type="text"
                           name="halo[tenant]"
                           value="{{ old('halo.tenant', $settings['halo']['tenant'] ?? '') }}"
                           placeholder="yourtenant"
                           style="width:100%;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Tenant / account name (if using the hosted HaloPSA cloud).
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="halo_client_id" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Client ID
                    </label>
                    <input id="halo_client_id"
                           type="text"
                           name="halo[client_id]"
                           value="{{ old('halo.client_id', $settings['halo']['client_id'] ?? '') }}"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:0;">
                    <label for="halo_api_key" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
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
                               style="flex:1;">

                        <button type="button"
                                class="btn-accent"
                                style="white-space:nowrap;padding:8px 12px;"
                                onclick="(function(){const input=document.getElementById('halo_api_key');if(input && input.value==='********'){input.value='';}if(input){input.focus();}})();">
                            Update key
                        </button>
                    </div>

                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Stored securely; value is not displayed. Click "Update key" to set a new one.
                    </small>
                </div>

                @php
                    $haloMap = \App\Services\Halo\HaloAssetMapping::fromArray(is_array($settings['halo'] ?? null) ? $settings['halo'] : []);
                    $assetFieldLabels = \App\Services\Halo\HaloAssetMapping::POINTS;
                    $haloRaw = is_array($settings['halo'] ?? null) ? $settings['halo'] : [];
                @endphp
                <div id="halo-asset-mapping" style="margin-top:16px;padding:14px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--surface-muted);">
                    <h4 style="margin:0 0 4px;font-size:14px;font-weight:600;color:var(--text);">Domain asset type &amp; field mapping</h4>
                    <p style="margin:0 0 12px;font-size:12px;color:var(--dd-text-soft);">
                        DomainDash reads your HaloPSA asset types, then you choose which one holds domains and which of its fields receives each value.
                        Save the HaloPSA connection details above first; loading uses the <em>saved</em> credentials.
                    </p>

                    <input type="hidden" id="halo_asset_type_name" name="halo[asset_type_name]" value="{{ old('halo.asset_type_name', $haloRaw['asset_type_name'] ?? '') }}">

                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                        <button type="button" class="btn-accent" id="haloLoadTypesBtn" style="padding:7px 14px;font-size:13px;" onclick="haloLoadAssetTypes(false)">Load asset types</button>
                        <button type="button" id="haloRefreshTypesBtn" class="dd-list__btn" style="display:none;" onclick="haloLoadAssetTypes(true)">Refresh</button>
                        <span id="haloMapStatus" role="status" aria-live="polite" style="font-size:12px;color:var(--dd-text-soft);"></span>
                    </div>
                    <p id="haloCurrentType" style="margin:8px 0 0;font-size:13px;color:var(--text);">
                        @if($haloMap->typeId)
                            Current asset type: <strong>{{ $haloMap->typeName !== '' ? $haloMap->typeName : 'Unnamed' }}</strong> (id {{ $haloMap->typeId }})
                        @else
                            No asset type selected. DomainDash falls back to the asset type named <strong>Domain</strong>.
                        @endif
                    </p>

                    <div id="haloTypePicker" style="display:none;margin-top:10px;">
                        <label for="haloAssetTypeSelect" style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted);font-weight:500;">Asset type for domains</label>
                        <input type="search" id="haloAssetTypeFilter" placeholder="Filter asset types…" aria-label="Filter asset types" style="width:100%;margin-bottom:6px;">
                        <select id="haloAssetTypeSelect" style="width:100%;" onchange="haloLoadFields(this.value, false)"></select>
                    </div>

                    <div id="haloMappingBox" style="display:none;margin-top:14px;">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px;">
                            <h5 style="margin:0;font-size:13px;font-weight:600;color:var(--text);">Data point &rarr; Halo field</h5>
                            <button type="button" class="dd-list__btn" onclick="haloLoadFields(document.getElementById('halo_asset_type_id').value, true)">Reload fields</button>
                        </div>
                        <div id="haloMappingRows" style="display:grid;gap:8px;"></div>
                        <div id="haloMappingWarnings" style="margin-top:8px;font-size:12px;color:var(--dd-danger-text);"></div>
                        <p style="margin:10px 0 0;font-size:11px;color:var(--dd-text-soft);">
                            Changing the asset type or any mapping re-pushes every domain on the next sync (one call per domain, rate limited).
                        </p>
                    </div>

                    <div style="margin-top:14px;">
                        <h5 style="margin:0 0 4px;font-size:13px;font-weight:600;color:var(--text);">Key fields</h5>
                        <p style="margin:0 0 8px;font-size:12px;color:var(--dd-text-soft);">
                            HaloPSA shows up to three key fields per asset type in its lists. Pick which DomainDash value fills each one (normally the domain name; leave empty if the type has no key field in that position).
                            <span id="haloKeyHint"></span>
                        </p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
                            @foreach([1, 2, 3] as $slot)
                                <div>
                                    <label for="halo_key_field_{{ $slot }}" style="display:block;font-size:12px;margin-bottom:3px;color:var(--text-muted);font-weight:500;">Key field {{ $slot }} <span data-halo-key-name="{{ $slot }}"></span></label>
                                    <select id="halo_key_field_{{ $slot }}" name="halo[asset_key_fields][{{ $slot }}]" style="width:100%;">
                                        <option value="">Leave empty</option>
                                        @foreach($assetFieldLabels as $pk => $pl)
                                            <option value="{{ $pk }}" @selected(($haloMap->keyFields[$slot] ?? '') === $pk)>{{ $pl }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);">
                        <h5 style="margin:0 0 4px;font-size:13px;font-weight:600;color:var(--text);">Test mapping (dry run)</h5>
                        <p style="margin:0 0 8px;font-size:12px;color:var(--dd-text-soft);">Shows what would be sent for one domain, using the mapping as it is in this form. Nothing is sent to HaloPSA.</p>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                            <input type="text" id="haloPreviewDomain" list="haloPreviewDomains" placeholder="Domain name, e.g. example.com" aria-label="Domain to preview" style="flex:1 1 220px;" onfocus="haloLoadPreviewDomains()">
                            <datalist id="haloPreviewDomains"></datalist>
                            <button type="button" class="btn-accent" style="padding:7px 14px;font-size:13px;" onclick="haloPreviewMapping()">Test mapping</button>
                        </div>
                        <div id="haloPreviewOut" role="status" aria-live="polite" style="margin-top:10px;font-size:13px;"></div>
                    </div>

                    <details style="margin-top:14px;" @if(!$haloMap->typeId) open @endif>
                        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-muted);">Advanced: manual IDs</summary>
                        <p style="margin:8px 0 10px;font-size:12px;color:var(--dd-text-soft);">
                            The pickers above fill these boxes. You can also type ids directly (HaloPSA &rarr; Config &rarr; Asset Types). Use <strong>0</strong> to not sync a value; blank restores the built-in default (148/149/150/165).
                        </p>
                        <div style="margin-bottom:12px;">
                            <label for="halo_asset_type_id" style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted);font-weight:500;">Asset type ID</label>
                            <input id="halo_asset_type_id" type="number" min="1" step="1" name="halo[asset_type_id]" value="{{ old('halo.asset_type_id', $haloMap->typeId) }}" placeholder="blank = asset type named &quot;Domain&quot;" style="width:100%;max-width:260px;">
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
                            @foreach($assetFieldLabels as $fieldKey => $fieldLabel)
                                <div>
                                    <label for="halo_field_{{ $fieldKey }}" style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">{{ $fieldLabel }} field ID</label>
                                    <input id="halo_field_{{ $fieldKey }}" type="number" min="0" step="1"
                                           name="halo[asset_field_ids][{{ $fieldKey }}]"
                                           value="{{ old('halo.asset_field_ids.'.$fieldKey, $haloMap->fieldIds[$fieldKey]) }}"
                                           oninput="haloManualFieldEdited('{{ $fieldKey }}')"
                                           style="width:100%;">
                                    <input type="hidden" id="halo_field_name_{{ $fieldKey }}" name="halo[asset_field_names][{{ $fieldKey }}]" value="{{ old('halo.asset_field_names.'.$fieldKey, $haloMap->fieldNames[$fieldKey] ?? '') }}">
                                </div>
                            @endforeach
                        </div>
                    </details>
                </div>

                <div style="margin-top:16px;padding:14px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--surface-muted);">
                    @php
                        $ticketTypeMappings = old('halo.ticket_type_mappings', $settings['halo']['ticket_type_mappings'] ?? []);
                        if (!is_array($ticketTypeMappings)) {
                            $ticketTypeMappings = [];
                        }
                    @endphp
                    <h4 style="margin:0 0 10px;color:var(--text,#1e293b);font-size:14px;">Support ticket type mappings</h4>
                    <p style="margin:0 0 12px;color:var(--dd-text-soft);font-size:12px;">
                        Map DomainDash service categories to HaloPSA ticket types used for create/sync/list filters.
                    </p>

                    <div style="display:grid;gap:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;padding:8px 10px;border-radius:8px;background:rgba(148,163,184,0.15);font-size:12px;color:var(--text-muted,#64748b);font-weight:700;">
                            <span>Service Category</span>
                            <span>Ticket Class</span>
                            <span>Halo Ticket Type</span>
                            <span>Action</span>
                        </div>
                    </div>
                    <div id="halo-ticket-type-mapping-list" style="display:grid;gap:10px;margin-top:8px;">
                        @forelse($ticketTypeMappings as $index => $mapping)
                            <div class="halo-ticket-mapping-row" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;background:var(--surface-muted);padding:10px;border:1px solid var(--border-subtle);border-radius:8px;">
                                <div>
                                    <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Service Category</label>
                                    <input type="text"
                                           name="halo[ticket_type_mappings][{{ $index }}][service_category]"
                                           value="{{ $mapping['service_category'] ?? '' }}"
                                           class="dd-input">
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Ticket Class</label>
                                    <select name="halo[ticket_type_mappings][{{ $index }}][ticket_type]" class="dd-input">
                                        <option value="Support/Issue" @selected(($mapping['ticket_type'] ?? 'Support/Issue') === 'Support/Issue')>Support/Issue</option>
                                        <option value="Service Request" @selected(($mapping['ticket_type'] ?? '') === 'Service Request')>Service Request</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Halo Ticket Type</label>
                                    <input type="hidden"
                                           name="halo[ticket_type_mappings][{{ $index }}][halo_ticket_type_id]"
                                           value="{{ $mapping['halo_ticket_type_id'] ?? '' }}">
                                    <input type="text"
                                           name="halo[ticket_type_mappings][{{ $index }}][halo_ticket_type_name]"
                                           value="{{ $mapping['halo_ticket_type_name'] ?? '' }}"
                                           placeholder="Choose ticket type"
                                           class="dd-input">
                                </div>
                                <button type="button"
                                        onclick="removeHaloTicketTypeMapping(this)"
                                        style="height:38px;padding:0 12px;border-radius:6px;border:1px solid rgba(239,68,68,0.5);background:rgba(239,68,68,0.1);color:var(--dd-danger-text);cursor:pointer;">
                                    Remove
                                </button>
                            </div>
                        @empty
                            <div id="halo-ticket-type-empty" style="padding:10px;border:1px dashed rgba(148,163,184,0.4);border-radius:8px;color:var(--dd-text-soft);font-size:13px;">
                                No mappings configured yet.
                            </div>
                        @endforelse
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
                        <button type="button"
                                class="btn-accent"
                                style="padding:8px 12px;"
                                onclick="openHaloTicketTypeMappingModal();">
                            Add Mapping
                        </button>
                    </div>
                </div>

                <div style="margin-top:16px;padding:14px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--surface-muted);">
                    @php
                        $statusMappings = old('halo.status_mappings', $settings['halo']['status_mappings'] ?? []);
                        if (!is_array($statusMappings)) {
                            $statusMappings = [];
                        }
                    @endphp
                    <h4 style="margin:0 0 10px;color:var(--text,#1e293b);font-size:14px;">Support status mappings</h4>
                    <p style="margin:0 0 12px;color:var(--dd-text-soft);font-size:12px;">
                        Map HaloPSA statuses to a DomainDash status label for the Support Requests table.
                    </p>

                    <div style="display:grid;gap:8px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;padding:8px 10px;border-radius:8px;background:rgba(148,163,184,0.15);font-size:12px;color:var(--text-muted,#64748b);font-weight:700;">
                            <span>DomainDash Status</span>
                            <span>Halo Status</span>
                            <span>Action</span>
                        </div>
                    </div>
                    <div id="halo-status-mapping-list" style="display:grid;gap:10px;margin-top:8px;">
                        @forelse($statusMappings as $index => $mapping)
                            <div class="halo-status-mapping-row" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;background:var(--surface-muted);padding:10px;border:1px solid var(--border-subtle);border-radius:8px;">
                                <div>
                                    <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">DomainDash Status</label>
                                    <input type="text"
                                           name="halo[status_mappings][{{ $index }}][domaindash_status]"
                                           value="{{ $mapping['domaindash_status'] ?? '' }}"
                                           placeholder="e.g. Open, In Progress, Closed"
                                           class="dd-input">
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Halo Status</label>
                                    <input type="hidden"
                                           name="halo[status_mappings][{{ $index }}][halo_status_id]"
                                           value="{{ $mapping['halo_status_id'] ?? '' }}">
                                    <input type="text"
                                           name="halo[status_mappings][{{ $index }}][halo_status_name]"
                                           value="{{ $mapping['halo_status_name'] ?? '' }}"
                                           placeholder="Choose Halo status"
                                           class="dd-input">
                                </div>
                                <button type="button"
                                        onclick="removeHaloStatusMapping(this)"
                                        style="height:38px;padding:0 12px;border-radius:6px;border:1px solid rgba(239,68,68,0.5);background:rgba(239,68,68,0.1);color:var(--dd-danger-text);cursor:pointer;">
                                    Remove
                                </button>
                            </div>
                        @empty
                            <div id="halo-status-empty" style="padding:10px;border:1px dashed rgba(148,163,184,0.4);border-radius:8px;color:var(--dd-text-soft);font-size:13px;">
                                No status mappings configured yet.
                            </div>
                        @endforelse
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
                        <button type="button"
                                class="btn-accent"
                                style="padding:8px 12px;"
                                onclick="openHaloStatusMappingModal();">
                            Add Status Mapping
                        </button>
                    </div>
                </div>
                </div>
            </div>

            <template id="halo-ticket-type-mapping-template">
                <div class="halo-ticket-mapping-row" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;background:var(--surface-muted);padding:10px;border:1px solid var(--border-subtle);border-radius:8px;">
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Service Category</label>
                        <input type="text" data-field="service_category" class="dd-input">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Ticket Class</label>
                        <select data-field="ticket_type" class="dd-input">
                            <option value="Support/Issue">Support/Issue</option>
                            <option value="Service Request">Service Request</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Halo Ticket Type</label>
                        <input type="hidden" data-field="halo_ticket_type_id">
                        <input type="text" data-field="halo_ticket_type_name" readonly class="dd-input" placeholder="Choose ticket type">
                    </div>
                    <button type="button" onclick="removeHaloTicketTypeMapping(this)" style="height:38px;padding:0 12px;border-radius:6px;border:1px solid rgba(239,68,68,0.5);background:rgba(239,68,68,0.1);color:var(--dd-danger-text);cursor:pointer;">Remove</button>
                </div>
            </template>

            <div id="haloTicketTypeMappingModal" style="display:none;position:fixed;inset:0;background:var(--dd-backdrop);z-index:10000;align-items:center;justify-content:center;">
                <div style="width:min(720px,92vw);background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;padding:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h3 style="margin:0;font-size:18px;color:var(--text,#111827);">Add ticket type mapping</h3>
                        <button type="button" onclick="closeHaloTicketTypeMappingModal()" style="background:none;border:none;color:var(--dd-text-soft);font-size:22px;cursor:pointer;">&times;</button>
                    </div>
                    <div style="display:grid;gap:12px;">
                        <div>
                            <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Service Category</label>
                            <select id="haloMappingServiceCategorySelect" class="dd-input"></select>
                        </div>
                        <div id="haloMappingCustomServiceWrap" style="display:none;">
                            <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Custom Service Category</label>
                            <input id="haloMappingCustomServiceInput" type="text" class="dd-input" placeholder="Enter service category">
                        </div>
                        <div>
                            <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Halo Ticket Type</label>
                            <div style="display:flex;gap:8px;">
                                <select id="haloMappingTicketTypeSelect" class="dd-input" style="flex:1;">
                                    <option value="">Select Halo ticket type</option>
                                </select>
                                <button type="button" class="btn-accent" style="padding:8px 12px;" onclick="loadHaloTicketTypesForMapping()">Load Types</button>
                            </div>
                        </div>
                        <div>
                            <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Ticket Class</label>
                            <select id="haloMappingTicketClassSelect" class="dd-input">
                                <option value="Support/Issue">Support/Issue</option>
                                <option value="Service Request">Service Request</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
                        <button type="button" onclick="closeHaloTicketTypeMappingModal()" style="padding:8px 12px;border-radius:6px;border:1px solid rgba(148,163,184,0.3);background:transparent;color:var(--dd-text-soft);cursor:pointer;">Cancel</button>
                        <button type="button" class="btn-accent" style="padding:8px 12px;" onclick="confirmHaloTicketTypeMapping()">Add</button>
                    </div>
                </div>
            </div>

            <template id="halo-status-mapping-template">
                <div class="halo-status-mapping-row" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;background:var(--surface-muted);padding:10px;border:1px solid var(--border-subtle);border-radius:8px;">
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">DomainDash Status</label>
                        <input type="text" data-field="domaindash_status" class="dd-input" placeholder="e.g. Open, In Progress, Closed">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Halo Status</label>
                        <input type="hidden" data-field="halo_status_id">
                        <input type="text" data-field="halo_status_name" readonly class="dd-input" placeholder="Choose Halo status">
                    </div>
                    <button type="button" onclick="removeHaloStatusMapping(this)" style="height:38px;padding:0 12px;border-radius:6px;border:1px solid rgba(239,68,68,0.5);background:rgba(239,68,68,0.1);color:var(--dd-danger-text);cursor:pointer;">Remove</button>
                </div>
            </template>

            <div id="haloStatusMappingModal" style="display:none;position:fixed;inset:0;background:var(--dd-backdrop);z-index:10000;align-items:center;justify-content:center;">
                <div style="width:min(640px,92vw);background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;padding:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h3 style="margin:0;font-size:18px;color:var(--text,#111827);">Add status mapping</h3>
                        <button type="button" onclick="closeHaloStatusMappingModal()" style="background:none;border:none;color:var(--dd-text-soft);font-size:22px;cursor:pointer;">&times;</button>
                    </div>
                    <div style="display:grid;gap:12px;">
                        <div>
                            <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">DomainDash Status</label>
                            <input id="haloMappingDomainDashStatusInput" type="text" class="dd-input" placeholder="e.g. Open, In Progress, Closed">
                        </div>
                        <div>
                            <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:600;">Halo Status</label>
                            <div style="display:flex;gap:8px;">
                                <select id="haloMappingStatusSelect" class="dd-input" style="flex:1;">
                                    <option value="">Select Halo status</option>
                                </select>
                                <button type="button" class="btn-accent" style="padding:8px 12px;" onclick="loadHaloStatusesForMapping()">Load Statuses</button>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
                        <button type="button" onclick="closeHaloStatusMappingModal()" style="padding:8px 12px;border-radius:6px;border:1px solid rgba(148,163,184,0.3);background:transparent;color:var(--dd-text-soft);cursor:pointer;">Cancel</button>
                        <button type="button" class="btn-accent" style="padding:8px 12px;" onclick="confirmHaloStatusMapping()">Add</button>
                    </div>
                </div>
            </div>

            {{-- ITGLUE SECTION --}}
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('itglue')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">ITGlue</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">Documentation platform integration</p>
                        </div>
                    </div>
                    <svg id="itglue-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="itglue-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="itglue_base_url" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Base URL
                    </label>
                    <input id="itglue_base_url"
                           type="text"
                           name="itglue[base_url]"
                           value="{{ $settings['itglue']['base_url'] ?? '' }}"
                           placeholder="https://api.itglue.com"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:0;">
                    <label for="itglue_api_key" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        API Key
                    </label>
                    <input id="itglue_api_key"
                           type="text"
                           name="itglue[api_key]"
                           value="{{ $settings['itglue']['api_key'] ?? '' }}"
                           placeholder="API Key"
                           style="width:100%;">
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
                    <label for="itglue_flexible_asset_type_id" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Flexible Asset Type ID
                    </label>
                    <input id="itglue_flexible_asset_type_id"
                           type="text"
                           name="itglue[flexible_asset_type_id]"
                           value="{{ $settings['itglue']['flexible_asset_type_id'] ?? '' }}"
                           placeholder="e.g. 4521154692859938"
                           style="width:100%;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Required when syncing domains to flexible assets.
                    </small>
                </div>

                <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Domain trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][domain]" value="{{ $flexibleAssetTraits['domain'] ?? '' }}" style="width:100%;" placeholder="domain-name">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Name servers trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][name_servers]" value="{{ $flexibleAssetTraits['name_servers'] ?? '' }}" style="width:100%;" placeholder="name-servers">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Expiry trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][expiry]" value="{{ $flexibleAssetTraits['expiry'] ?? '' }}" style="width:100%;" placeholder="expiry">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">WHOIS trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][whois]" value="{{ $flexibleAssetTraits['whois'] ?? '' }}" style="width:100%;" placeholder="whois">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">DNS trait key</label>
                        <input type="text" name="itglue[flexible_asset_traits][dns]" value="{{ $flexibleAssetTraits['dns'] ?? '' }}" style="width:100%;" placeholder="dns">
                    </div>
                </div>

                @php
                    $itgPoints = ['domain' => 'Domain name', 'name_servers' => 'Name servers', 'expiry' => 'Expiry date', 'whois' => 'WHOIS / registrant summary', 'dns' => 'DNS records'];
                    $itgDefaults = \App\Services\ItGlue\ItGlueClient::DEFAULT_TRAITS;
                @endphp
                <div id="itglue-asset-mapping" style="margin-top:16px;padding:14px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--surface-muted);">
                    <h4 style="margin:0 0 4px;font-size:14px;font-weight:600;color:var(--text);">Flexible asset type &amp; field mapping</h4>
                    <p style="margin:0 0 12px;font-size:12px;color:var(--dd-text-soft);">
                        Load your enabled flexible asset types, choose the one that holds domains, then choose which field receives each value.
                        Save the base URL and API key first; loading uses the <em>saved</em> credentials.
                    </p>
                    <input type="hidden" id="itglue_flexible_asset_type_name" name="itglue[flexible_asset_type_name]" value="{{ old('itglue.flexible_asset_type_name', $settings['itglue']['flexible_asset_type_name'] ?? '') }}">

                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                        <button type="button" class="btn-accent" style="padding:7px 14px;font-size:13px;" onclick="itgLoadTypes(false)">Load flexible asset types</button>
                        <button type="button" id="itgRefreshBtn" class="dd-list__btn" style="display:none;" onclick="itgLoadTypes(true)">Refresh</button>
                        <span id="itgMapStatus" role="status" aria-live="polite" style="font-size:12px;color:var(--dd-text-soft);"></span>
                    </div>
                    <p style="margin:8px 0 0;font-size:13px;color:var(--text);">
                        @if(!empty($settings['itglue']['flexible_asset_type_id']))
                            Current type: <strong>{{ ($settings['itglue']['flexible_asset_type_name'] ?? '') !== '' ? $settings['itglue']['flexible_asset_type_name'] : 'Unnamed' }}</strong> (id {{ $settings['itglue']['flexible_asset_type_id'] }})
                        @else
                            No flexible asset type selected. Domain sync to IT Glue needs one.
                        @endif
                    </p>

                    <div id="itgTypePicker" style="display:none;margin-top:10px;">
                        <label for="itgTypeSelect" style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted);font-weight:500;">Flexible asset type for domains</label>
                        <input type="search" id="itgTypeFilter" placeholder="Filter types…" aria-label="Filter flexible asset types" style="width:100%;margin-bottom:6px;">
                        <select id="itgTypeSelect" style="width:100%;" onchange="itgLoadFields(this.value, false)"></select>
                    </div>

                    <div id="itgMappingBox" style="display:none;margin-top:14px;">
                        <h5 style="margin:0 0 6px;font-size:13px;font-weight:600;color:var(--text);">Data point &rarr; IT Glue field</h5>
                        <div id="itgMappingRows" style="display:grid;gap:8px;"></div>
                        <div id="itgMappingWarnings" style="margin-top:8px;font-size:12px;color:var(--dd-danger-text);"></div>
                        <p style="margin:10px 0 0;font-size:11px;color:var(--dd-text-soft);">
                            Only Text, Textbox and Date fields can be filled. Tag, Select, Upload and other kinds are shown greyed out.
                            Changing the mapping re-pushes every domain on the next sync; fields that were mapped before keep their old values in IT Glue.
                        </p>
                    </div>

                    <details style="margin-top:14px;" @if(empty($settings['itglue']['flexible_asset_type_id'])) open @endif>
                        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-muted);">Advanced: manual IDs and trait keys</summary>
                        <div style="margin-top:10px;">
                            <label for="itglue_flexible_asset_type_id" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Flexible Asset Type ID</label>
                            <input id="itglue_flexible_asset_type_id"
                                   type="text"
                                   inputmode="numeric"
                                   name="itglue[flexible_asset_type_id]"
                                   value="{{ old('itglue.flexible_asset_type_id', $settings['itglue']['flexible_asset_type_id'] ?? '') }}"
                                   placeholder="e.g. 4521154692859938"
                                   style="width:100%;">
                            <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">Required when syncing domains to flexible assets.</small>
                        </div>
                        <p style="margin:12px 0 6px;font-size:12px;color:var(--dd-text-soft);">Trait keys (the "name-key" of each field). Blank means that value is not synced.</p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                            @foreach($itgPoints as $pk => $pl)
                                <div>
                                    <label for="itglue_trait_{{ $pk }}" style="display:block;font-size:13px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">{{ $pl }} trait key</label>
                                    <input id="itglue_trait_{{ $pk }}" type="text" name="itglue[flexible_asset_traits][{{ $pk }}]" value="{{ old('itglue.flexible_asset_traits.'.$pk, $flexibleAssetTraits[$pk] ?? '') }}" style="width:100%;" placeholder="{{ $itgDefaults[$pk] }}" pattern="[a-z0-9_\-]*">
                                </div>
                            @endforeach
                        </div>
                    </details>
                </div>
                </div>
            </div>

            {{-- IP2WHOIS SECTION --}}
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('ip2whois')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">IP2WHOIS</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">IP2Location.io WHOIS lookups</p>
                        </div>
                    </div>
                    <svg id="ip2whois-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="ip2whois-content" class="settings-content" style="padding:20px 24px;display:none;">

                    <div style="margin-bottom:12px;">
                        <label for="ip2whois_api_key" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                            API Key
                        </label>
                        <input id="ip2whois_api_key"
                               type="text"
                               name="ip2whois[api_key]"
                               value="{{ $settings['ip2whois']['api_key'] ?? '' }}"
                               placeholder="Enter IP2WHOIS API key"
                               style="width:100%;">
                        <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                            Get your key from IP2Location.io &gt; IP2WHOIS.
                        </small>
                    </div>

                    <button type="button" class="btn-accent" style="margin-top:12px;" onclick="openIp2whoisModal();">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Sync WHOIS (select domains)
                    </button>
                </div>
            </div>

            {{-- SMTP SECTION --}}
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('smtp')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">SMTP</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">Email server configuration</p>
                        </div>
                    </div>
                    <svg id="smtp-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="smtp-content" class="settings-content" style="padding:20px 24px;display:none;">

                <div style="margin-bottom:12px;">
                    <label for="smtp_host" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        SMTP Host
                    </label>
                    <input id="smtp_host"
                           type="text"
                           name="smtp[host]"
                           value="{{ $settings['smtp']['host'] ?? '' }}"
                           placeholder="smtp.example.com"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_port" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Port
                    </label>
                    <input id="smtp_port"
                           type="number"
                           name="smtp[port]"
                           value="{{ $settings['smtp']['port'] ?? 587 }}"
                           placeholder="587"
                           style="width:100%;">
                    <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                        Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)
                    </small>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_encryption" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Encryption
                    </label>
                    <select id="smtp_encryption"
                            name="smtp[encryption]"
                            class="dd-input">
                        <option value="">None</option>
                        <option value="tls" {{ ($settings['smtp']['encryption'] ?? '') === 'tls' ? 'selected' : '' }}>TLS</option>
                        <option value="ssl" {{ ($settings['smtp']['encryption'] ?? '') === 'ssl' ? 'selected' : '' }}>SSL</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_username" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Username
                    </label>
                    <input id="smtp_username"
                           type="text"
                           name="smtp[username]"
                           value="{{ $settings['smtp']['username'] ?? '' }}"
                           placeholder="SMTP username (if required)"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_password" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        Password
                    </label>
                    <input id="smtp_password"
                           type="password"
                           name="smtp[password]"
                           value="{{ $settings['smtp']['password'] ?? '' }}"
                           placeholder="SMTP password (if required)"
                           autocomplete="new-password"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:12px;">
                    <label for="smtp_from" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        From Address
                    </label>
                    <input id="smtp_from"
                           type="email"
                           name="smtp[from]"
                           value="{{ $settings['smtp']['from'] ?? '' }}"
                           placeholder="noreply@yourdomain.com"
                           style="width:100%;">
                </div>

                <div style="margin-bottom:0;">
                    <label for="smtp_from_name" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                        From Name
                    </label>
                    <input id="smtp_from_name"
                           type="text"
                           name="smtp[from_name]"
                           value="{{ $settings['smtp']['from_name'] ?? 'DomainDash' }}"
                           placeholder="DomainDash"
                           style="width:100%;">
                </div>
                </div>
            </div>

            {{-- SYNC SCHEDULER SECTION --}}
            @php
                $syncSchedule = $settings['sync_schedule'] ?? [];
                $syncFrequencies = ['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly'];
                $serverTimezone = date_default_timezone_get();
            @endphp
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('sync-scheduler')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">Sync Scheduler</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">Configure recurring integration sync jobs</p>
                        </div>
                    </div>
                    <svg id="sync-scheduler-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="sync-scheduler-content" class="settings-content" style="padding:20px 24px;display:none;">
                    <div style="margin-bottom:16px;">
                        <small style="display:block;font-size:12px;color:var(--dd-text-soft);">
                            Scheduler uses the server timezone: <strong>{{ $serverTimezone }}</strong>.
                        </small>
                        <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                            If your server timezone is Australia/Adelaide, UTC+9:30 and daylight savings are handled automatically.
                        </small>
                        <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">
                            Ensure cron runs <code>php artisan schedule:run</code> every minute to trigger these sync tasks.
                        </small>
                    </div>
                    @foreach([
                        'sync_domains' => 'Sync Domains from Synergy',
                        'sync_hosting_services' => 'Sync Hosting Services',
                        'sync_halo_assets' => 'Sync Client Domain Assets to/from HaloPSA',
                        'sync_itglue' => 'Sync IT Glue Domain Assets',
                    ] as $key => $label)
                        <div class="dd-sync-scheduler-row" style="display:grid;grid-template-columns:180px 160px 120px;gap:12px;align-items:end;margin-bottom:12px;padding:12px;">
                            <div>
                                <input type="hidden" name="sync_schedule[{{ $key }}][enabled]" value="0">
                                <label class="dd-sync-scheduler-toggle" style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:500;cursor:pointer;">
                                    <input type="checkbox"
                                           class="dd-checkbox dd-sync-scheduler-check"
                                           name="sync_schedule[{{ $key }}][enabled]"
                                           value="1"
                                           {{ !empty($syncSchedule[$key]['enabled']) ? 'checked' : '' }}>
                                    {{ $label }}
                                </label>
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;margin-bottom:4px;">Frequency</label>
                                <select name="sync_schedule[{{ $key }}][frequency]" class="dd-field"
                                        style="width:100%;">
                                    @foreach($syncFrequencies as $value => $text)
                                        <option value="{{ $value }}" {{ ($syncSchedule[$key]['frequency'] ?? 'daily') === $value ? 'selected' : '' }}>{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;margin-bottom:4px;">Run time</label>
                                <input type="text" class="dd-field dd-sync-scheduler-time"
                                       name="sync_schedule[{{ $key }}][time]"
                                       value="{{ $syncSchedule[$key]['time'] ?? '02:00' }}"
                                       inputmode="numeric"
                                       maxlength="5"
                                       pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                       title="Use 24-hour format HH:MM"
                                       placeholder="HH:MM"
                                       style="width:100%;font-size:13px;">
                            </div>
                        </div>
                    @endforeach
                    <small style="display:block;margin-top:8px;font-size:12px;color:var(--dd-text-soft);">
                        Hourly sync uses the minutes from "Run time"; weekly sync runs every Monday at the selected time.
                    </small>
                </div>
            </div>


            {{-- MFA SECTION --}}
            @php
                $mfaSettings = $settings['mfa'] ?? [];
            @endphp
            <div class="settings-section" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;margin-bottom:16px;overflow:hidden;">
                <div class="settings-header" onclick="toggleSection('mfa')" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);transition:background 0.2s;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">Multi-Factor Authentication</h3>
                            <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">Configure default MFA behavior and challenge persistence</p>
                        </div>
                    </div>
                    <svg id="mfa-icon" style="width:20px;height:20px;transition:transform 0.3s;color:var(--dd-text-soft);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
                <div id="mfa-content" class="settings-content" style="padding:20px 24px;display:none;">
                    <div style="margin-bottom:12px;">
                        <label for="mfa_persistence_days" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">MFA persistence window (days)</label>
                        <input id="mfa_persistence_days" type="number" min="1" max="365" name="mfa[persistence_days]" value="{{ $mfaSettings['persistence_days'] ?? 30 }}" style="width:100%;">
                        <small style="display:block;margin-top:4px;font-size:12px;color:var(--dd-text-soft);">How long to trust a remembered browser before re-prompting for MFA.</small>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label for="mfa_default_method" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">Primary MFA method</label>
                        <select id="mfa_default_method" name="mfa[default_method]" class="dd-input">
                            <option value="authenticator_app" {{ ($mfaSettings['default_method'] ?? 'authenticator_app') === 'authenticator_app' ? 'selected' : '' }}>Authenticator app (TOTP)</option>
                            <option value="email_otp" {{ ($mfaSettings['default_method'] ?? '') === 'email_otp' ? 'selected' : '' }}>Email one-time passcode</option>
                        </select>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
                        <label style="display:flex;align-items:center;gap:8px;color:var(--text,#111827);font-size:14px;">
                            <input type="hidden" name="mfa[allow_recovery_codes]" value="0">
                            <input type="checkbox" name="mfa[allow_recovery_codes]" value="1" {{ !empty($mfaSettings['allow_recovery_codes']) ? 'checked' : '' }}>
                            Allow recovery codes
                        </label>

                        <label style="display:flex;align-items:center;gap:8px;color:var(--text,#111827);font-size:14px;">
                            <input type="hidden" name="mfa[remember_browser]" value="0">
                            <input type="checkbox" name="mfa[remember_browser]" value="1" {{ !empty($mfaSettings['remember_browser']) ? 'checked' : '' }}>
                            Allow remembered devices
                        </label>
                    </div>

                    <div style="margin-top:12px;">
                        <label for="mfa_issuer" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">MFA issuer label</label>
                        <input id="mfa_issuer" type="text" name="mfa[issuer]" value="{{ $mfaSettings['issuer'] ?? config('app.name') }}" style="width:100%;">
                    </div>
                </div>
            </div>

            {{-- Action buttons --}}
            <div class="dd-settings-panel" style="padding:20px;">
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <button type="submit" class="btn-accent" style="padding:12px 32px;font-size:15px;font-weight:600;display:inline-flex;align-items:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save All Settings
                    </button>
                    <button type="button" onclick="openHaloSyncModal()" class="ui-btn ui-btn--primary ui-btn--lg">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                        Sync with Halo
                    </button>
                    <button type="button" onclick="openItGlueSyncModal()" class="ui-btn ui-btn--primary ui-btn--lg">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                        Sync IT Glue
                    </button>
                </div>
            </div>
        </form>

        {{-- SMTP test card --}}
        <div class="dd-settings-panel" style="margin-top:16px;overflow:hidden;">
            <div style="padding:16px 20px;background:var(--surface-muted);border-bottom:1px solid var(--border-subtle);">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;background:var(--dd-accent-soft);color:var(--dd-accent-text);border-radius:var(--dd-radius);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div>
                        <h3 style="font-size:16px;font-weight:600;margin:0;color:var(--text,#111827);">SMTP Test</h3>
                        <p style="font-size:13px;color:var(--text-muted,#566177);margin:0;">Send a test email to verify your configuration</p>
                    </div>
                </div>
            </div>
            <div style="padding:20px 24px;">
                <form method="POST" action="{{ route('admin.settings.smtp-test') }}">
                    @csrf

                    <div style="margin-bottom:12px;">
                        <label for="smtp_test_to" style="display:block;font-size:14px;margin-bottom:4px;color:var(--text-muted,#566177);font-weight:500;">
                            Test recipient
                        </label>
                        <input id="smtp_test_to"
                               type="email"
                               name="to"
                               placeholder="test@yourdomain.com"
                               required
                               style="width:100%;">
                    </div>

                    <button type="submit" class="btn-accent" style="padding:10px 20px;">
                        Send Test Email
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Halo Sync Modal --}}
    <div id="haloSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:var(--dd-backdrop);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;max-width:1200px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border-subtle);display:flex;justify-content:space-between;align-items:center;">
                <h2 style="font-size:20px;font-weight:700;margin:0;color:var(--text);display:flex;align-items:center;gap:8px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync with HaloPSA</h2>
                <button onclick="closeHaloSyncModal()" style="background:none;border:none;color:var(--dd-text-soft);font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                @include('admin.sync._worker-banner')
                <div style="display:flex;gap:16px;margin-bottom:24px;">
                    <button onclick="showHaloClientSync()" id="haloClientsBtn" class="sync-option-btn" style="flex:1;padding:16px;background:var(--dd-success);border:2px solid var(--dd-success);border-radius:10px;color:#fff;font-weight:600;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Sync Clients
                    </button>
                    <button onclick="showHaloDomainSync()" id="haloDomainsBtn" class="sync-option-btn" style="flex:1;padding:16px;background:rgba(15,23,42,0.4);border:2px solid rgba(148,163,184,0.2);border-radius:8px;color:var(--text-muted);font-weight:600;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        Sync Domains
                    </button>
                </div>

                <div id="haloClientSyncContent" style="display:block;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:var(--text);margin:0;">Client Mapping</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadHaloClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                Refresh List
                            </button>
                            <button onclick="syncHaloClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                Sync Selected
                            </button>
                        </div>
                    </div>
                    <div id="haloClientRunPanel" aria-live="polite"></div>
                    <div id="haloClientList" style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:16px;">
                        <div style="text-align:center;color:var(--text-muted);padding:40px;">
                            Click "Refresh List" to load clients from HaloPSA
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeHaloSyncModal()" style="padding:10px 20px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-danger) 35%, transparent);border-radius:10px;color:var(--dd-danger);cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>

                <div id="haloDomainSyncContent" style="display:none;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:var(--text);margin:0;">Domain Assets</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadHaloDomains()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                Refresh List
                            </button>
                            <button onclick="syncHaloDomains()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                Sync Selected
                            </button>
                        </div>
                    </div>
                    <div id="haloDomainRunPanel" aria-live="polite"></div>
                    <div id="haloDomainList" style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:16px;">
                        <div style="text-align:center;color:var(--text-muted);padding:40px;">
                            Click "Refresh List" to load domains from DomainDash
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeHaloSyncModal()" style="padding:10px 20px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-danger) 35%, transparent);border-radius:10px;color:var(--dd-danger);cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- HaloPSA Sync Progress Modal --}}
    <div id="haloSyncProgressModal" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,0.6);backdrop-filter:blur(2px);z-index:13000;align-items:center;justify-content:center;padding:20px;">
        <div style="min-width:220px;background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:10px;color:var(--text);">
            <span style="width:18px;height:18px;border:2px solid rgba(148,163,184,0.45);border-top-color:var(--text);border-radius:999px;display:inline-block;animation:halo-spin 0.7s linear infinite;flex-shrink:0;"></span>
            <span id="haloSyncProgressTitle"></span>
        </div>
    </div>

    {{-- Sync Result Modal --}}
    <div id="syncResultModal" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,0.6);backdrop-filter:blur(2px);z-index:14000;align-items:center;justify-content:center;padding:20px;">
        <div style="width:min(90vw,480px);background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;overflow:hidden;">
            <div style="padding:18px 22px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:12px;">
                <span id="syncResultIcon" style="flex-shrink:0;display:flex;"></span>
                <h3 id="syncResultTitle" style="font-size:16px;font-weight:700;color:var(--text);margin:0;"></h3>
            </div>
            <div style="padding:18px 22px;">
                <p id="syncResultMessage" style="margin:0;color:var(--text);font-size:14px;line-height:1.5;"></p>
                <div id="syncResultErrors" style="display:none;margin-top:16px;">
                    <p style="font-size:12px;font-weight:600;color:var(--text-muted);margin:0 0 8px;text-transform:uppercase;letter-spacing:0.06em;">Failed Items</p>
                    <div id="syncResultErrorList" style="border:1px solid var(--border-subtle);border-radius:8px;overflow:hidden;max-height:220px;overflow-y:auto;"></div>
                </div>
            </div>
            <div style="padding:0 22px 18px;display:flex;justify-content:flex-end;">
                <button onclick="closeSyncResultModal()" class="btn-accent" style="padding:9px 22px;">Done</button>
            </div>
        </div>
    </div>

    {{-- IT Glue Sync Modal --}}
    <div id="itglueSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:var(--dd-backdrop);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;max-width:1200px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border-subtle);display:flex;justify-content:space-between;align-items:center;">
                <h2 style="font-size:20px;font-weight:700;margin:0;color:var(--text);display:flex;align-items:center;gap:8px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync with IT Glue</h2>
                <button onclick="closeItGlueSyncModal()" style="background:none;border:none;color:var(--dd-text-soft);font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                @include('admin.sync._worker-banner')
                <div style="display:flex;gap:16px;margin-bottom:24px;">
                    <button onclick="showItGlueClientSync()" id="itglueClientsBtn" class="sync-option-btn" style="flex:1;padding:16px;background:var(--dd-accent);border:2px solid var(--dd-accent);border-radius:8px;color:var(--dd-accent-contrast);font-weight:600;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Sync Clients
                    </button>
                    <button onclick="showItGlueConfigSync()" id="itglueConfigBtn" class="sync-option-btn" style="flex:1;padding:16px;background:rgba(15,23,42,0.4);border:2px solid rgba(148,163,184,0.2);border-radius:8px;color:var(--text-muted);font-weight:600;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                        Sync Configurations
                    </button>
                </div>

                <div id="itglueClientSyncContent" style="display:block;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:var(--text);margin:0;">Organization Mapping</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadItGlueClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                Refresh List
                            </button>
                            <button onclick="syncItGlueClients()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                Save Mappings
                            </button>
                        </div>
                    </div>
                    <div id="itglueClientList" style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:16px;">
                        <div style="text-align:center;color:var(--text-muted);padding:40px;">
                            Click "Refresh List" to load clients from DomainDash
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeItGlueSyncModal()" style="padding:10px 20px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-danger) 35%, transparent);border-radius:10px;color:var(--dd-danger);cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>

                <div id="itglueConfigSyncContent" style="display:none;">
                    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="font-size:16px;font-weight:600;color:var(--text);margin:0;">Configuration Items</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="loadItGlueConfigs()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                Refresh List
                            </button>
                            <button onclick="syncItGlueConfigs()" class="btn-accent" style="padding:8px 16px;font-size:14px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                Sync Selected
                            </button>
                        </div>
                    </div>
                    <div id="itglueConfigRunPanel" aria-live="polite"></div>
                    <div id="itglueConfigList" style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:16px;">
                        <div style="text-align:center;color:var(--text-muted);padding:40px;">
                            Click "Refresh List" to load configuration items
                        </div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                        <button onclick="closeItGlueSyncModal()" style="padding:10px 20px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-danger) 35%, transparent);border-radius:10px;color:var(--dd-danger);cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- IP2WHOIS Sync Modal --}}
    <div id="ip2whoisSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:var(--dd-backdrop);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;max-width:900px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border-subtle);display:flex;justify-content:space-between;align-items:center;">
                <h2 style="font-size:20px;font-weight:700;margin:0;color:var(--text);display:flex;align-items:center;gap:8px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Sync WHOIS (IP2WHOIS)</h2>
                <button onclick="closeIp2whoisModal()" style="background:none;border:none;color:var(--dd-text-soft);font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="font-size:16px;font-weight:600;color:var(--text);margin:0;">Domains</h3>
                    <div style="display:flex;gap:8px;">
                        <button onclick="loadIp2whoisDomains()" class="btn-accent" style="padding:8px 16px;font-size:14px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Refresh List</button>
                        <button onclick="syncIp2whoisDomains()" class="btn-accent" style="padding:8px 16px;font-size:14px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Sync Selected</button>
                    </div>
                </div>
                <div id="ip2whoisDomainList" style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:16px;">
                    <div style="text-align:center;color:var(--text-muted);padding:40px;">
                        Click "Refresh List" to load domains
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

        // IP2WHOIS Modal
        function openIp2whoisModal() {
            document.getElementById('ip2whoisSyncModal').style.display = 'flex';
            loadIp2whoisDomains();
        }

        function closeIp2whoisModal() {
            document.getElementById('ip2whoisSyncModal').style.display = 'none';
        }

        // Add hover effect to section headers
        document.addEventListener('DOMContentLoaded', function() {
            const headers = document.querySelectorAll('.settings-header');
            headers.forEach(header => {
                header.addEventListener('mouseenter', function() {
                    this.style.background = 'var(--border-subtle)';
                });
                header.addEventListener('mouseleave', function() {
                    this.style.background = 'var(--surface-muted)';
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
            document.getElementById('haloClientsBtn').style.background = 'var(--dd-accent)';
            document.getElementById('haloClientsBtn').style.borderColor = '#10b981';
            document.getElementById('haloClientsBtn').style.color = '#fff';
            document.getElementById('haloDomainsBtn').style.background = 'var(--surface-muted)';
            document.getElementById('haloDomainsBtn').style.borderColor = 'var(--border-subtle)';
            document.getElementById('haloDomainsBtn').style.color = 'var(--text-muted)';
        }

        function showHaloDomainSync() {
            document.getElementById('haloClientSyncContent').style.display = 'none';
            document.getElementById('haloDomainSyncContent').style.display = 'block';
            document.getElementById('haloDomainsBtn').style.background = 'var(--dd-accent)';
            document.getElementById('haloDomainsBtn').style.borderColor = '#10b981';
            document.getElementById('haloDomainsBtn').style.color = '#fff';
            document.getElementById('haloClientsBtn').style.background = 'var(--surface-muted)';
            document.getElementById('haloClientsBtn').style.borderColor = 'var(--border-subtle)';
            document.getElementById('haloClientsBtn').style.color = 'var(--text-muted)';
        }

        function ddSyncBarHtml(sync, url) {
            const when = sync && sync.last_synced_human ? 'Last synced ' + sync.last_synced_human : 'Never synced';
            const busy = sync && sync.active ? ' (refresh running: ' + sync.active.status + ')' : '';
            return `<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;font-size:13px;color:var(--text-muted);">
                <span>${when}${busy}</span>
                <button type="button" onclick="ddRefreshMirror('${url}', this)" style="padding:4px 12px;background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:6px;color:var(--text);cursor:pointer;">Refresh now</button>
            </div><div data-dd-runs></div>`;
        }

        function ddEsc(v) {
            return String(v ?? '').replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        async function ddRefreshMirror(url, btn) {
            const mount = btn.parentElement.nextElementSibling;
            btn.disabled = true;
            try {
                const r = await DDSync.request(url, 'POST');
                const runs = r.data.runs || (r.data.run ? [r.data.run] : []);
                if (!r.ok || !runs.length) {
                    mount.textContent = 'Refresh not started: ' + (r.data.error || r.data.message || ('HTTP ' + r.status));
                    return;
                }
                DDSync.updateWorker(r.data.worker);
                DDSync.track(runs, mount, { onDone: () => { btn.disabled = false; } });
            } catch (e) {
                mount.textContent = 'Refresh failed: ' + e.message;
            } finally {
                setTimeout(() => { btn.disabled = false; }, 3000);
            }
        }

        // ---------------------------------------------------------------
        // Shared list helpers (DDList lives in resources/js/dd-list.js)
        // ---------------------------------------------------------------
        const DD_SPINNER_HTML = (label) => '<div style="display:flex;align-items:center;justify-content:center;padding:40px;"><div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:10px;color:var(--text);"><span style="width:18px;height:18px;border:2px solid rgba(148,163,184,0.45);border-top-color:var(--text);border-radius:999px;display:inline-block;animation:halo-spin 0.7s linear infinite;"></span><span>' + ddEsc(label) + '</span></div></div>';

        function ddTextEl(tag, text, css) {
            const n = document.createElement(tag);
            n.textContent = text === null || text === undefined ? '' : String(text);
            if (css) n.style.cssText = css;
            return n;
        }

        function ddNameCell(row, nameKey) {
            const wrap = document.createElement('div');
            wrap.appendChild(ddTextEl('div', row[nameKey || 'name'] || ''));
            if (row.sync_error) {
                const err = ddTextEl('div', 'Last sync failed: ' + row.sync_error);
                err.className = 'dd-list__err';
                wrap.appendChild(err);
            }
            return wrap;
        }

        function ddSyncSorts() { return [
            { value: 'status', label: 'Status (failed / not synced first)', compare: DDList.std.status((r) => (r.sync_error ? 0 : (r.synced_at ? 2 : 1))) },
            { value: 'name-asc', label: 'Name A–Z', compare: DDList.std.name('name', 1) },
            { value: 'name-desc', label: 'Name Z–A', compare: DDList.std.name('name', -1) },
            { value: 'synced-new', label: 'Last synced (newest)', compare: DDList.std.date('synced_at', -1) },
            { value: 'synced-old', label: 'Last synced (oldest)', compare: DDList.std.date('synced_at', 1) },
            { value: 'client', label: 'Client', compare: (a, b) => DDList.collator.compare(a.client || '', b.client || '') },
        ]; }
        function ddSyncFilters() { return [
            { value: 'all', label: 'All', match: () => true },
            { value: 'unsynced', label: 'Not synced', match: (r, st) => st.key === 'unsynced' || st.key === 'queued' },
            { value: 'failed', label: 'Failed', match: (r, st) => st.key === 'failed' },
            { value: 'synced', label: 'Synced', match: (r, st) => st.key === 'synced' || st.key === 'unchanged' },
        ]; }

        function ddCreateDomainList(mount, rows, o) {
            return DDList.create({
                mount: mount,
                rows: rows,
                idKey: 'id',
                storageKey: o.storageKey,
                grid: o.grid,
                columns: o.columns,
                searchKeys: ['name', 'client'],
                sorts: ddSyncSorts(),
                defaultSort: 'status',
                filters: ddSyncFilters(),
                statusOf: DDList.syncStatus(o.existsKey),
                emptyText: 'No domains to show.',
            });
        }

        function ddMapSorts() { return [
            { value: 'status', label: 'Status (unmapped first)', compare: (a, b, statusOf) => (statusOf(a).rank - statusOf(b).rank) },
            { value: 'name-asc', label: 'Name A–Z', compare: DDList.std.name('name', 1) },
            { value: 'name-desc', label: 'Name Z–A', compare: DDList.std.name('name', -1) },
        ]; }
        function ddMapFilters() { return [
            { value: 'all', label: 'All', match: () => true },
            { value: 'unmapped', label: 'Unmapped', match: (r, st) => st.key === 'unsynced' },
            { value: 'mapped', label: 'Mapped', match: (r, st) => st.key === 'synced' },
        ]; }
        const ddMapStatus = (row) => (row.mapped_id
            ? { key: 'synced', label: 'Mapped', rank: 1, title: 'Linked to a record in the other system' }
            : { key: 'unsynced', label: 'Unmapped', rank: 0, variant: 'warning', title: 'Not linked yet' });

        // Refresh a domain list in place (no spinner, selection and sort kept) after a run finished.
        async function ddReloadDomainList(url, listVar, rowsKey) {
            try {
                const response = await fetch(url);
                const data = await response.json();
                const list = window[listVar];
                if (list && Array.isArray(data[rowsKey])) {
                    list.clearItemStates();
                    list.setRows(data[rowsKey]);
                }
            } catch (e) { /* the next manual refresh will show current data */ }
            if (window[listVar]) window[listVar].setBusy(false);
        }

        async function loadHaloClients() {
            const listEl = document.getElementById('haloClientList');
            listEl.innerHTML = DD_SPINNER_HTML('Loading clients from HaloPSA...');

            try {
                const response = await fetch('/admin/sync/halo/clients');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = ddSyncBarHtml(data.sync, '/admin/sync/halo/refresh') + `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderHaloClientList(data.clients, data.sync);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load clients: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderHaloClientList(clients, sync) {
            const listEl = document.getElementById('haloClientList');
            listEl.innerHTML = ddSyncBarHtml(sync, '/admin/sync/halo/refresh');
            const mount = document.createElement('div');
            listEl.appendChild(mount);

            const rows = clients.map((c) => Object.assign({}, c, { id: c.halo_id, name: c.halo_name, sel: c.mapped_id ? String(c.mapped_id) : '' }));

            window._haloClientList = DDList.create({
                mount: mount,
                rows: rows,
                idKey: 'id',
                storageKey: 'halo-clients',
                grid: '1fr 1fr 130px 110px',
                searchKeys: ['name'],
                rowText: (r) => ((r.suggestions || []).find((s) => String(s.id) === r.sel) || {}).name || '',
                sorts: ddMapSorts().concat([{ value: 'updated', label: 'Updated (newest)', compare: DDList.std.date('updated', -1) }]),
                defaultSort: 'status',
                filters: ddMapFilters(),
                statusOf: ddMapStatus,
                emptyText: 'No HaloPSA clients to show.',
                columns: [
                    { label: 'HaloPSA Client', render: (r) => ddTextEl('div', r.name) },
                    { label: 'DomainDash Client', render: (r) => {
                        const sel = document.createElement('select');
                        sel.className = 'dd-list__select';
                        sel.setAttribute('aria-label', 'DomainDash client for ' + r.name);
                        const none = ddTextEl('option', '-- Select Client --');
                        none.value = '';
                        sel.appendChild(none);
                        (r.suggestions || []).forEach((s) => {
                            const o = ddTextEl('option', s.name);
                            o.value = s.id;
                            if (String(s.id) === r.sel) o.selected = true;
                            sel.appendChild(o);
                        });
                        sel.addEventListener('change', () => { r.sel = sel.value; });
                        return sel;
                    } },
                    { label: 'Updated', className: '', render: (r) => ddTextEl('div', r.updated || 'N/A', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Status', render: (r, l, st) => DDList.chip(st) },
                ],
            });
        }

        async function syncHaloClients() {
            const list = window._haloClientList;
            const selectedClients = list ? list.selectedRows().filter(r => r.sel).map(r => ({ halo_id: r.halo_id, dash_client_id: r.sel, _name: r.name || ('Client ' + r.halo_id) })) : [];

            if (selectedClients.length === 0) {
                alert('Please select at least one client to sync');
                return;
            }

            try {
                showHaloSyncProgress('Saving mappings and queueing asset refresh…');

                const response = await fetch('/admin/sync/halo/clients/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ clients: selectedClients })
                });
                const data = await response.json();
                const errors = (data.results || []).filter(r => !r.success).map(r => ({ label: r.item?.name || 'Client', message: r.error || 'Unknown error' }));
                if (!data.success && data.error) errors.push({ label: 'Request', message: data.error });

                hideHaloSyncProgress();
                const panel = document.getElementById('haloClientRunPanel');
                panel.replaceChildren();
                if (errors.length) {
                    const box = document.createElement('div');
                    box.className = 'dd-run';
                    box.setAttribute('role', 'alert');
                    const t = document.createElement('div');
                    t.className = 'dd-run__error';
                    t.textContent = 'Some clients could not be linked:';
                    box.appendChild(t);
                    const ul = document.createElement('ul');
                    ul.className = 'dd-run__list';
                    errors.forEach(e => { const li = document.createElement('li'); li.textContent = e.label + ': ' + e.message; ul.appendChild(li); });
                    box.appendChild(ul);
                    panel.appendChild(box);
                }
                DDSync.updateWorker(data.worker);
                if (data.runs && data.runs.length) {
                    const mount = document.createElement('div');
                    panel.appendChild(mount);
                    DDSync.track(data.runs, mount, { label: 'Refresh Halo assets for linked clients', onDone: () => loadHaloClients() });
                }
            } catch (error) {
                hideHaloSyncProgress();
                showSyncResult('Sync Failed', error.message, [], null);
            }
        }

        async function loadHaloDomains() {
            const listEl = document.getElementById('haloDomainList');
            listEl.innerHTML = DD_SPINNER_HTML('Loading domains from DomainDash...');

            try {
                const response = await fetch('/admin/sync/halo/domains');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderHaloDomainList(data.domains, data.sync);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load domains: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderHaloDomainList(domains, sync) {
            const listEl = document.getElementById('haloDomainList');
            listEl.innerHTML = ddSyncBarHtml(sync, '/admin/sync/halo/refresh');
            const mount = document.createElement('div');
            listEl.appendChild(mount);

            window._haloDomainList = ddCreateDomainList(mount, domains, {
                storageKey: 'halo-domains',
                existsKey: 'exists_in_halo',
                grid: '1.4fr 1fr 100px 150px',
                columns: [
                    { label: 'Domain Name', render: (r) => ddNameCell(r) },
                    { label: 'Client', render: (r) => ddTextEl('div', r.client || 'No Client', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Expiry', render: (r) => ddTextEl('div', r.expiry ? String(r.expiry).slice(0, 10) : 'N/A', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Status', render: (r, l, st) => DDList.chip(st) },
                ],
            });
        }

        // Shared by Halo and IT Glue: queue, then show Queued -> Syncing -> Synced/Failed per row plus the overall bar.
        async function ddQueueDomainRun(opts) {
            const list = window[opts.listVar];
            const panel = document.getElementById(opts.panelId);
            const ids = list ? list.selectedIds() : [];
            if (!ids.length) {
                alert(opts.emptyMessage);
                return;
            }

            try {
                showHaloSyncProgress('Queueing sync…');
                const r = await opts.request(ids);
                const data = r.data;
                hideHaloSyncProgress();

                if (!r.ok || !data.success) {
                    panel.replaceChildren();
                    const box = document.createElement('div');
                    box.className = 'dd-run';
                    box.setAttribute('role', 'alert');
                    const err = document.createElement('div');
                    err.className = 'dd-run__error';
                    err.textContent = 'Sync not started: ' + (data.error || data.message || ('HTTP ' + r.status));
                    box.appendChild(err);
                    panel.appendChild(box);
                    return;
                }

                DDSync.updateWorker(data.worker);
                list.setBusy(true);
                const binder = DDList.bindRun(list, {
                    ids: ids,
                    itemsUrl: (runId) => '/admin/sync/runs/' + encodeURIComponent(runId) + '/items',
                });
                binder.start();
                DDSync.track([data.run], panel, {
                    label: opts.label(ids.length),
                    runNow: opts.runNow ? () => opts.runNow(ids) : undefined,
                    onUpdate: (run) => binder.onUpdate(run),
                    onDone: async (run) => {
                        await binder.onDone(run);
                        // Let people see the final per-row result, then re-sort from fresh server data.
                        setTimeout(() => ddReloadDomainList(opts.reloadUrl, opts.listVar, opts.rowsKey), 1500);
                    },
                });
            } catch (error) {
                hideHaloSyncProgress();
                panel.textContent = 'Sync failed: ' + error.message;
                if (list) list.setBusy(false);
            }
        }

        function syncHaloDomains() {
            return ddQueueDomainRun({
                listVar: '_haloDomainList',
                panelId: 'haloDomainRunPanel',
                emptyMessage: 'Please select at least one domain to sync',
                request: (ids) => DDSync.request('/admin/sync/halo/domains/sync', 'POST', { domain_ids: ids }),
                runNow: (ids) => DDSync.request('/admin/sync/halo/domains/run-now', 'POST', { domain_ids: ids }),
                label: (n) => 'Sync ' + n + ' domain' + (n === 1 ? '' : 's') + ' to HaloPSA',
                reloadUrl: '/admin/sync/halo/domains',
                rowsKey: 'domains',
            });
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
            document.getElementById('itglueClientsBtn').style.background = 'var(--dd-accent)';
            document.getElementById('itglueClientsBtn').style.borderColor = '#f59e0b';
            document.getElementById('itglueClientsBtn').style.color = '#fff';
            document.getElementById('itglueConfigBtn').style.background = 'var(--surface-muted)';
            document.getElementById('itglueConfigBtn').style.borderColor = 'var(--border-subtle)';
            document.getElementById('itglueConfigBtn').style.color = 'var(--text-muted)';
        }

        function showItGlueConfigSync() {
            document.getElementById('itglueClientSyncContent').style.display = 'none';
            document.getElementById('itglueConfigSyncContent').style.display = 'block';
            document.getElementById('itglueConfigBtn').style.background = 'var(--dd-accent)';
            document.getElementById('itglueConfigBtn').style.borderColor = '#f59e0b';
            document.getElementById('itglueConfigBtn').style.color = '#fff';
            document.getElementById('itglueClientsBtn').style.background = 'var(--surface-muted)';
            document.getElementById('itglueClientsBtn').style.borderColor = 'var(--border-subtle)';
            document.getElementById('itglueClientsBtn').style.color = 'var(--text-muted)';
        }

        async function loadItGlueClients() {
            const listEl = document.getElementById('itglueClientList');
            listEl.innerHTML = DD_SPINNER_HTML('Loading clients...');

            try {
                const response = await fetch('/admin/sync/itglue/clients');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = ddSyncBarHtml(data.sync, '/admin/sync/itglue/refresh') + `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderItGlueClientList(data.clients, data.organizations || [], data.sync);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load clients: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderItGlueClientList(clients, organizations, sync) {
            const listEl = document.getElementById('itglueClientList');
            listEl.innerHTML = ddSyncBarHtml(sync, '/admin/sync/itglue/refresh');
            const mount = document.createElement('div');
            listEl.appendChild(mount);

            const orgName = (id) => (organizations.find((o) => String(o.id) === String(id)) || {}).name || '';
            const rows = clients.map((c) => Object.assign({}, c, { id: c.dash_id, name: c.dash_name, sel: c.mapped_id ? String(c.mapped_id) : '' }));

            window._itglueClientList = DDList.create({
                mount: mount,
                rows: rows,
                idKey: 'id',
                storageKey: 'itglue-orgs',
                grid: '1fr 1fr 110px 120px',
                searchKeys: ['name'],
                rowText: (r) => orgName(r.sel),
                sorts: ddMapSorts(),
                defaultSort: 'status',
                filters: ddMapFilters(),
                statusOf: ddMapStatus,
                emptyText: 'No DomainDash clients to show.',
                selectable: false,
                columns: [
                    { label: 'DomainDash Client', render: (r) => ddTextEl('div', r.name) },
                    { label: 'IT Glue Organization', render: (r) => {
                        const sel = document.createElement('select');
                        sel.className = 'dd-list__select itglue-client-mapping';
                        sel.setAttribute('aria-label', 'IT Glue organisation for ' + r.name);
                        const fill = () => {
                            if (sel.dataset.full) return;
                            sel.dataset.full = '1';
                            sel.replaceChildren();
                            const none = ddTextEl('option', '-- Select Organization --');
                            none.value = '';
                            sel.appendChild(none);
                            organizations.forEach((org) => {
                                const o = ddTextEl('option', org.name);
                                o.value = org.id;
                                sel.appendChild(o);
                            });
                            sel.value = r.sel;
                        };
                        // Thousands of selects x all organisations is heavy: fill the options on first use.
                        const none = ddTextEl('option', '-- Select Organization --');
                        none.value = '';
                        sel.appendChild(none);
                        if (r.sel) { const o = ddTextEl('option', orgName(r.sel) || ('#' + r.sel)); o.value = r.sel; o.selected = true; sel.appendChild(o); }
                        ['focus', 'mousedown'].forEach((ev) => sel.addEventListener(ev, fill, { once: true }));
                        sel.addEventListener('change', () => { r.sel = sel.value; });
                        r._fill = fill;
                        r._select = sel;
                        return sel;
                    } },
                    { label: 'Status', render: (r, l, st) => DDList.chip(st) },
                    { label: 'Action', render: (r) => {
                        if (r.mapped_id) return ddTextEl('span', '✓ Mapped', 'color:var(--dd-status-success-fg);font-size:13px;');
                        const b = ddTextEl('button', 'Suggest', 'padding:6px 12px;background:var(--dd-accent);border:none;border-radius:4px;color:var(--dd-accent-contrast);cursor:pointer;font-weight:600;font-size:13px;');
                        b.type = 'button';
                        b.addEventListener('click', () => suggestItGlueOrg(r));
                        return b;
                    } },
                ],
            });
        }

        async function suggestItGlueOrg(row) {
            try {
                const response = await fetch(`/admin/sync/itglue/suggest/${encodeURIComponent(row.id)}`);
                const data = await response.json();

                if (data.suggested_org_id) {
                    row.sel = String(data.suggested_org_id);
                    if (row._fill) row._fill();
                    if (row._select) row._select.value = row.sel;
                } else {
                    alert('No matching organization found');
                }
            } catch (error) {
                alert('Failed to suggest organization: ' + error.message);
            }
        }

        async function syncItGlueClients() {
            const list = window._itglueClientList;
            const mappings = list ? list.rows().filter(r => r.sel).map(r => ({ dash_client_id: r.id, itglue_org_id: r.sel, _name: r.name || ('Client ' + r.id) })) : [];

            if (mappings.length === 0) {
                alert('Please map at least one client');
                return;
            }

            const total = mappings.length;
            let mappedCount = 0;
            const errors = [];

            try {
                for (let i = 0; i < mappings.length; i++) {
                    showHaloSyncProgress(`Saving ${i + 1} / ${total} mapping${total !== 1 ? 's' : ''}…`);

                    const response = await fetch('/admin/sync/itglue/clients/sync', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({ mappings: [mappings[i]] })
                    });

                    const data = await response.json();
                    if (data.success) {
                        mappedCount += data.mapped_count;
                    } else {
                        errors.push({ label: mappings[i]._name, message: data.error || 'Unknown error' });
                    }
                }

                hideHaloSyncProgress();
                closeItGlueSyncModal();
                showSyncResult(
                    errors.length === 0 ? 'Mappings Saved' : 'Saved with Errors',
                    `Successfully saved ${mappedCount} mapping${mappedCount !== 1 ? 's' : ''}.`,
                    errors,
                    () => location.reload()
                );
            } catch (error) {
                hideHaloSyncProgress();
                showSyncResult('Save Failed', error.message, [], () => location.reload());
            }
        }

        async function loadIp2whoisDomains() {
            const listEl = document.getElementById('ip2whoisDomainList');
            listEl.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:40px;"><div style="font-size:32px;margin-bottom:12px;">⏳</div>Loading domains…</div>';

            try {
                const response = await fetch('/admin/sync/ip2whois/domains');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderIp2whoisDomainList(data.items || []);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load domains: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderIp2whoisDomainList(items) {
            const listEl = document.getElementById('ip2whoisDomainList');

            let html = `
                <div style="margin-bottom:16px;padding:12px;background:var(--surface-muted);border-radius:6px;display:grid;grid-template-columns:40px 1fr 1fr 140px 100px;gap:12px;align-items:center;font-weight:600;color:var(--dd-text-soft);font-size:13px;">
                    <input type="checkbox" id="selectAllIp2whois" onchange="toggleAllIp2whois(this)" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                    <div>Name</div>
                    <div>Client</div>
                    <div>Last synced</div>
                    <div style="text-align:center;">Status</div>
                </div>
            `;

            items.forEach(item => {
                const statusColor = item.has_data ? '#10b981' : '#94a3b8';
                const statusText = item.has_data ? 'Cached' : 'Not synced';
                const syncedAt = item.synced_at ? new Date(item.synced_at).toLocaleString() : '—';

                html += `
                    <div style="padding:12px;background:var(--surface-soft, var(--surface-muted));border-radius:6px;margin-bottom:8px;display:grid;grid-template-columns:40px 1fr 1fr 140px 100px;gap:12px;align-items:center;">
                        <input type="checkbox" class="ip2whois-checkbox" data-item-id="${ddEsc(item.id)}" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                        <div style="color:var(--text);">${ddEsc(item.name)}</div>
                        <div style="color:var(--dd-text-soft);font-size:13px;">${item.client || 'No client'}</div>
                        <div style="color:var(--dd-text-soft);font-size:13px;">${syncedAt}</div>
                        <div style="text-align:center;color:${statusColor};font-size:13px;">${statusText}</div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }

        function toggleAllIp2whois(checkbox) {
            document.querySelectorAll('.ip2whois-checkbox').forEach(cb => cb.checked = checkbox.checked);
        }

        async function syncIp2whoisDomains() {
            const selected = [];
            document.querySelectorAll('.ip2whois-checkbox:checked').forEach(cb => {
                selected.push({ id: cb.dataset.itemId });
            });

            if (selected.length === 0) {
                alert('Please select at least one domain to sync');
                return;
            }

            showGlobalSpinner('Syncing WHOIS data…');

            try {
                const response = await fetch('/admin/sync/ip2whois/domains/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ items: selected })
                });

                const data = await response.json();

                if (data.success) {
                    alert(`Synced ${data.synced_count} domain(s)`);
                    loadIp2whoisDomains();
                } else {
                    alert('Sync failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Sync failed: ' + error.message);
            } finally {
                hideGlobalSpinner();
            }
        }

        async function loadItGlueConfigs() {
            const listEl = document.getElementById('itglueConfigList');
            listEl.innerHTML = DD_SPINNER_HTML('Loading configuration items...');

            try {
                const response = await fetch('/admin/sync/itglue/configurations');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderItGlueConfigList(data.items, data.sync);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load items: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderItGlueConfigList(items, sync) {
            const listEl = document.getElementById('itglueConfigList');
            listEl.innerHTML = ddSyncBarHtml(sync, '/admin/sync/itglue/refresh');
            const mount = document.createElement('div');
            listEl.appendChild(mount);

            window._itglueConfigList = ddCreateDomainList(mount, items, {
                storageKey: 'itglue-configs',
                existsKey: 'exists_in_itglue',
                grid: '1.4fr 1fr 90px 150px',
                columns: [
                    { label: 'Name', render: (r) => ddNameCell(r) },
                    { label: 'Client', render: (r) => ddTextEl('div', r.client || 'No Client', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Type', render: (r) => ddTextEl('div', r.type || '', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Status', render: (r, l, st) => DDList.chip(st) },
                ],
            });
        }

        function syncItGlueConfigs() {
            return ddQueueDomainRun({
                listVar: '_itglueConfigList',
                panelId: 'itglueConfigRunPanel',
                emptyMessage: 'Please select at least one item to sync',
                request: (ids) => DDSync.request('/admin/sync/itglue/configurations/sync', 'POST', { items: ids.map((id) => ({ id: id, type: 'domain' })) }),
                label: (n) => 'Sync ' + n + ' domain' + (n === 1 ? '' : 's') + ' to IT Glue',
                reloadUrl: '/admin/sync/itglue/configurations',
                rowsKey: 'items',
            });
        }

        function showHaloSyncProgress(message) {
            document.getElementById('haloSyncProgressTitle').textContent = message;
            document.getElementById('haloSyncProgressModal').style.display = 'flex';
        }

        function hideHaloSyncProgress() {
            document.getElementById('haloSyncProgressModal').style.display = 'none';
        }

        let _syncResultOnClose = null;

        function showSyncResult(title, message, errors, onClose) {
            _syncResultOnClose = onClose || null;

            document.getElementById('syncResultTitle').textContent = title;
            document.getElementById('syncResultMessage').textContent = message;

            const hasErrors = errors && errors.length > 0;
            const iconEl = document.getElementById('syncResultIcon');

            if (hasErrors) {
                iconEl.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
                const list = document.getElementById('syncResultErrorList');
                list.innerHTML = errors.map((e, idx) => `
                    <div style="padding:10px 12px;${idx < errors.length - 1 ? 'border-bottom:1px solid var(--border-subtle);' : ''}display:flex;flex-direction:column;gap:3px;">
                        <span style="font-size:13px;font-weight:600;color:var(--text);">${ddEsc(e.label)}</span>
                        <span style="font-size:13px;color:var(--dd-danger-text);">${ddEsc(e.message)}</span>
                    </div>`).join('');
                document.getElementById('syncResultErrors').style.display = 'block';
            } else {
                iconEl.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`;
                document.getElementById('syncResultErrors').style.display = 'none';
            }

            document.getElementById('syncResultModal').style.display = 'flex';
        }

        function closeSyncResultModal() {
            document.getElementById('syncResultModal').style.display = 'none';
            if (_syncResultOnClose) {
                const cb = _syncResultOnClose;
                _syncResultOnClose = null;
                cb();
            }
        }

        function showGlobalSpinner(message = 'Working…') {
            if (typeof window.showGlobalLoader === 'function') {
                window.showGlobalLoader(message);
            }
        }

        function hideGlobalSpinner() {
            if (typeof window.hideGlobalLoader === 'function') {
                window.hideGlobalLoader();
            }
        }

        // ---------------------------------------------------------------
        // HaloPSA asset type / field mapping
        // ---------------------------------------------------------------
        const haloMapState = { fields: [], keyfields: [], suggestions: {}, points: {}, types: [] };

        function mapEl(tag, text, css) {
            const n = document.createElement(tag);
            if (text !== undefined && text !== null) n.textContent = String(text);
            if (css) n.style.cssText = css;
            return n;
        }

        function haloStatus(msg, isError) {
            const s = document.getElementById('haloMapStatus');
            s.textContent = msg || '';
            s.style.color = isError ? 'var(--dd-danger-text)' : 'var(--dd-text-soft)';
        }

        async function haloLoadAssetTypes(refresh) {
            haloStatus('Loading asset types from HaloPSA…');
            const r = await DDSync.request('/admin/settings/halo/asset-types' + (refresh ? '?refresh=1' : ''), 'GET');
            if (!r.ok) { haloStatus(r.data.error || ('Could not load asset types (HTTP ' + r.status + ')'), true); return; }

            haloMapState.types = r.data.types || [];
            const sel = document.getElementById('haloAssetTypeSelect');
            const storedId = document.getElementById('halo_asset_type_id').value;
            const chosen = storedId || (r.data.suggested_id ? String(r.data.suggested_id) : '');

            function fillTypes(filter) {
                const q = (filter || '').toLowerCase();
                const current = sel.value || chosen;
                sel.replaceChildren();
                const none = mapEl('option', '-- Select asset type --');
                none.value = '';
                sel.appendChild(none);
                haloMapState.types.filter((t) => !q || t.name.toLowerCase().includes(q) || String(t.id) === q || t.id === Number(current)).forEach((t) => {
                    const o = mapEl('option', t.name + (t.group ? ' (' + t.group + ')' : '') + ' · #' + t.id);
                    o.value = String(t.id);
                    sel.appendChild(o);
                });
                sel.value = current;
            }
            fillTypes('');
            document.getElementById('haloAssetTypeFilter').oninput = (e) => fillTypes(e.target.value);

            document.getElementById('haloTypePicker').style.display = '';
            document.getElementById('haloRefreshTypesBtn').style.display = '';
            haloStatus(haloMapState.types.length + ' asset types loaded.');
            if (chosen) haloLoadFields(chosen, !!refresh);
        }

        async function haloLoadFields(id, refresh) {
            if (!id) { document.getElementById('haloMappingBox').style.display = 'none'; return; }
            const typeIdInput = document.getElementById('halo_asset_type_id');
            const changedType = String(typeIdInput.value) !== String(id);
            haloStatus('Loading fields…');
            const r = await DDSync.request('/admin/settings/halo/asset-types/' + encodeURIComponent(id) + '/fields' + (refresh ? '?refresh=1' : ''), 'GET');
            if (!r.ok) { haloStatus(r.data.error || ('Could not load fields (HTTP ' + r.status + ')'), true); return; }

            const type = haloMapState.types.find((t) => String(t.id) === String(id));
            typeIdInput.value = id;
            document.getElementById('halo_asset_type_name').value = (type && type.name) || r.data.name || '';
            haloMapState.fields = r.data.fields || [];
            haloMapState.keyfields = r.data.keyfields || [];
            haloMapState.suggestions = r.data.suggestions || {};
            haloMapState.points = r.data.points || {};
            document.getElementById('haloAssetTypeSelect').value = String(id);
            haloRenderMapping(changedType);
            haloStatus(haloMapState.fields.length + ' fields in this asset type.');
            document.getElementById('haloCurrentType').textContent = 'Selected: ' + document.getElementById('halo_asset_type_name').value + ' (id ' + id + ') – save settings to apply.';
        }

        function haloRenderMapping(changedType) {
            const box = document.getElementById('haloMappingBox');
            const rows = document.getElementById('haloMappingRows');
            rows.replaceChildren();
            const fields = haloMapState.fields;

            Object.keys(haloMapState.points).forEach((point) => {
                const input = document.getElementById('halo_field_' + point);
                const currentId = Number(input.value || 0);
                const inType = fields.some((f) => f.id === currentId);
                const suggested = haloMapState.suggestions[point];

                // Keep the current id if this type has it; otherwise take the suggestion; otherwise keep it as a manual id.
                let pick = '';
                let note = '';
                if (!changedType && inType) { pick = String(currentId); note = 'current'; }
                else if (suggested) { pick = String(suggested); note = 'suggested'; }
                else if (currentId > 0) { pick = String(currentId); note = 'manual id, not found in this type'; }
                else { pick = '0'; note = 'no match found'; }

                const row = mapEl('div', null, 'display:grid;grid-template-columns:minmax(120px,170px) 1fr;gap:8px;align-items:center;');
                const lab = mapEl('label', haloMapState.points[point], 'font-size:13px;color:var(--text);font-weight:500;');
                lab.setAttribute('for', 'haloMap_' + point);
                const wrap = mapEl('div');
                const sel = mapEl('select', null, 'width:100%;');
                sel.id = 'haloMap_' + point;
                const dont = mapEl('option', "Don't sync this value");
                dont.value = '0';
                sel.appendChild(dont);
                fields.forEach((f) => {
                    const o = mapEl('option', f.name + ' · #' + f.id + (f.mandatory ? ' · required' : ''));
                    o.value = String(f.id);
                    sel.appendChild(o);
                });
                if (pick !== '0' && !fields.some((f) => String(f.id) === pick)) {
                    const o = mapEl('option', 'Manual id #' + pick);
                    o.value = pick;
                    sel.appendChild(o);
                }
                sel.value = pick;
                const hint = mapEl('div', note, 'font-size:11px;color:var(--dd-text-soft);margin-top:2px;');
                hint.id = 'haloMapNote_' + point;
                sel.addEventListener('change', () => { haloApplyPick(point, sel.value); hint.textContent = 'chosen'; });
                wrap.appendChild(sel);
                wrap.appendChild(hint);
                row.appendChild(lab);
                row.appendChild(wrap);
                rows.appendChild(row);
                haloApplyPick(point, pick, true);
            });
            box.style.display = '';
            haloSyncKeyFields(changedType);
        }

        function haloApplyPick(point, value, silent) {
            const id = Number(value || 0);
            document.getElementById('halo_field_' + point).value = id;
            const f = haloMapState.fields.find((x) => x.id === id);
            document.getElementById('halo_field_name_' + point).value = f ? f.name : '';
            if (!silent) haloSyncKeyFields(true);
            haloWarnMandatory();
        }

        // The user typed an id by hand: forget the picked name and move the dropdown if it knows the id.
        function haloManualFieldEdited(point) {
            document.getElementById('halo_field_name_' + point).value = '';
            const sel = document.getElementById('haloMap_' + point);
            if (!sel) return;
            const v = String(Number(document.getElementById('halo_field_' + point).value || 0));
            if (![...sel.options].some((o) => o.value === v)) {
                const o = mapEl('option', 'Manual id #' + v);
                o.value = v;
                sel.appendChild(o);
            }
            sel.value = v;
            const hint = document.getElementById('haloMapNote_' + point);
            if (hint) hint.textContent = 'manual id';
        }

        // Key field N of the Halo type is fed by whichever data point is mapped to that field.
        function haloSyncKeyFields(apply) {
            const kfs = haloMapState.keyfields;
            const names = document.querySelectorAll('[data-halo-key-name]');
            names.forEach((n) => { n.textContent = ''; });
            kfs.forEach((k) => {
                const label = document.querySelector('[data-halo-key-name="' + k.slot + '"]');
                if (label) label.textContent = k.name ? '(' + k.name + ')' : '';
                if (!apply) return;
                const sel = document.getElementById('halo_key_field_' + k.slot);
                if (!sel) return;
                let point = '';
                Object.keys(haloMapState.points).forEach((p) => {
                    if (k.field_id && Number(document.getElementById('halo_field_' + p).value) === k.field_id) point = p;
                });
                sel.value = point;
            });
            if (apply && kfs.length) {
                [1, 2, 3].forEach((slot) => {
                    if (!kfs.some((k) => k.slot === slot)) document.getElementById('halo_key_field_' + slot).value = '';
                });
            }
            document.getElementById('haloKeyHint').textContent = kfs.length ? ' Matched automatically from the asset type; adjust if needed.' : '';
        }

        function haloWarnMandatory() {
            const warn = [];
            const mapped = new Set();
            Object.keys(haloMapState.points).forEach((p) => mapped.add(Number(document.getElementById('halo_field_' + p).value)));
            haloMapState.fields.filter((f) => f.mandatory && !mapped.has(f.id)).forEach((f) => warn.push('"' + f.name + '" is required in HaloPSA but not mapped: creating assets may fail unless Halo has a default for it.'));
            Object.keys(haloMapState.points).forEach((p) => {
                if (Number(document.getElementById('halo_field_' + p).value) === 0) warn.push(haloMapState.points[p] + ' will not be synced.');
            });
            const seen = {};
            Object.keys(haloMapState.points).forEach((p) => {
                const v = Number(document.getElementById('halo_field_' + p).value);
                if (v > 0) { if (seen[v]) warn.push('Two values are mapped to the same field (#' + v + ').'); seen[v] = true; }
            });
            const out = document.getElementById('haloMappingWarnings');
            out.replaceChildren();
            warn.forEach((w) => out.appendChild(mapEl('div', w)));
        }

        let haloPreviewLoaded = false;
        async function haloLoadPreviewDomains() {
            if (haloPreviewLoaded) return;
            haloPreviewLoaded = true;
            try {
                const r = await DDSync.request('/admin/sync/halo/domains', 'GET');
                const dl = document.getElementById('haloPreviewDomains');
                (r.data.domains || []).slice(0, 500).forEach((d) => { const o = document.createElement('option'); o.value = d.name; dl.appendChild(o); });
            } catch (e) { /* free typing still works */ }
        }

        async function haloPreviewMapping() {
            const out = document.getElementById('haloPreviewOut');
            const name = document.getElementById('haloPreviewDomain').value.trim();
            out.replaceChildren();
            if (!name) { out.textContent = 'Enter a domain name first.'; return; }

            const ids = {}, names = {};
            Object.keys(haloMapState.points.length ? haloMapState.points : { domain: 1, expiry: 1, nameservers: 1, whois: 1 }).forEach((p) => {
                ids[p] = document.getElementById('halo_field_' + p).value;
                names[p] = document.getElementById('halo_field_name_' + p).value;
            });
            const keys = {};
            [1, 2, 3].forEach((n) => { keys[n] = document.getElementById('halo_key_field_' + n).value; });
            const typeId = document.getElementById('halo_asset_type_id').value;

            out.textContent = 'Building preview…';
            const r = await DDSync.request('/admin/settings/halo/preview-mapping', 'POST', {
                domain: name,
                halo: { asset_type_id: typeId || null, asset_type_name: document.getElementById('halo_asset_type_name').value, asset_field_ids: ids, asset_field_names: names, asset_key_fields: keys },
            });
            out.replaceChildren();
            if (!r.ok) {
                const msg = (r.data.errors && Object.values(r.data.errors)[0] && Object.values(r.data.errors)[0][0]) || r.data.message || r.data.error || ('HTTP ' + r.status);
                out.appendChild(mapEl('div', 'Preview failed: ' + msg, 'color:var(--dd-danger-text);'));
                return;
            }
            const d = r.data;
            out.appendChild(mapEl('div', 'Preview for ' + d.domain + (d.client ? ' (' + d.client + ')' : '') + ' → asset type ' + d.asset_type_name + (d.asset_type_id ? ' #' + d.asset_type_id : ' (looked up by name at sync time)') + '. Nothing was sent to HaloPSA.', 'margin-bottom:6px;'));
            const table = mapEl('table', null, 'width:100%;border-collapse:collapse;font-size:12px;');
            const head = mapEl('tr');
            ['DomainDash value', 'Goes to Halo field', 'Value sent'].forEach((h) => head.appendChild(mapEl('th', h, 'text-align:left;padding:4px 6px;border-bottom:1px solid var(--border-subtle);')));
            table.appendChild(head);
            d.rows.forEach((row) => {
                const tr = mapEl('tr', null, row.synced ? '' : 'opacity:.6;');
                tr.appendChild(mapEl('td', row.label, 'padding:4px 6px;vertical-align:top;'));
                tr.appendChild(mapEl('td', row.synced ? ((row.field_name ? row.field_name + ' ' : '') + '#' + row.field_id) : "Don't sync", 'padding:4px 6px;vertical-align:top;'));
                tr.appendChild(mapEl('td', row.synced ? row.value : '', 'padding:4px 6px;white-space:pre-wrap;word-break:break-word;max-width:420px;'));
                table.appendChild(tr);
            });
            d.key_fields.forEach((k) => {
                const tr = mapEl('tr');
                tr.appendChild(mapEl('td', 'Key field', 'padding:4px 6px;'));
                tr.appendChild(mapEl('td', k.property, 'padding:4px 6px;'));
                tr.appendChild(mapEl('td', k.value, 'padding:4px 6px;word-break:break-word;'));
                table.appendChild(tr);
            });
            out.appendChild(table);
            (d.warnings || []).forEach((w) => out.appendChild(mapEl('div', w, 'margin-top:4px;color:var(--dd-danger-text);')));
        }

        // ---------------------------------------------------------------
        // IT Glue flexible asset type / trait mapping
        // ---------------------------------------------------------------
        const itgState = { types: [], fields: [], points: {}, suggestions: {} };

        function itgStatus(msg, isError) {
            const s = document.getElementById('itgMapStatus');
            s.textContent = msg || '';
            s.style.color = isError ? 'var(--dd-danger-text)' : 'var(--dd-text-soft)';
        }

        async function itgLoadTypes(refresh) {
            itgStatus('Loading flexible asset types from IT Glue…');
            const r = await DDSync.request('/admin/settings/itglue/flexible-asset-types' + (refresh ? '?refresh=1' : ''), 'GET');
            if (!r.ok) { itgStatus(r.data.error || ('Could not load types (HTTP ' + r.status + ')'), true); return; }

            itgState.types = r.data.types || [];
            const sel = document.getElementById('itgTypeSelect');
            const stored = document.getElementById('itglue_flexible_asset_type_id').value.trim();
            const named = itgState.types.filter((t) => t.name.toLowerCase() === 'domain');
            const chosen = stored || (named.length === 1 ? named[0].id : '');

            function fill(filter) {
                const q = (filter || '').toLowerCase();
                const current = sel.value || chosen;
                sel.replaceChildren();
                const none = mapEl('option', '-- Select flexible asset type --');
                none.value = '';
                sel.appendChild(none);
                itgState.types.filter((t) => !q || t.name.toLowerCase().includes(q) || t.id === current).forEach((t) => {
                    const o = mapEl('option', t.name + ' · #' + t.id);
                    o.value = t.id;
                    sel.appendChild(o);
                });
                sel.value = current;
            }
            fill('');
            document.getElementById('itgTypeFilter').oninput = (e) => fill(e.target.value);
            document.getElementById('itgTypePicker').style.display = '';
            document.getElementById('itgRefreshBtn').style.display = '';
            itgStatus(itgState.types.length + ' flexible asset types loaded.');
            if (chosen) itgLoadFields(chosen, !!refresh);
        }

        async function itgLoadFields(id, refresh) {
            if (!id) { document.getElementById('itgMappingBox').style.display = 'none'; return; }
            const idInput = document.getElementById('itglue_flexible_asset_type_id');
            const changedType = String(idInput.value).trim() !== String(id);
            itgStatus('Loading fields…');
            const r = await DDSync.request('/admin/settings/itglue/flexible-asset-types/' + encodeURIComponent(id) + '/fields' + (refresh ? '?refresh=1' : ''), 'GET');
            if (!r.ok) { itgStatus(r.data.error || ('Could not load fields (HTTP ' + r.status + ')'), true); return; }

            const type = itgState.types.find((t) => t.id === String(id));
            idInput.value = id;
            document.getElementById('itglue_flexible_asset_type_name').value = type ? type.name : '';
            itgState.fields = r.data.fields || [];
            itgState.points = r.data.points || {};
            itgState.suggestions = r.data.suggestions || {};
            document.getElementById('itgTypeSelect').value = String(id);
            itgRenderMapping(changedType);
            itgStatus(itgState.fields.length + ' fields in this type. Save settings to apply.');
        }

        function itgRenderMapping(changedType) {
            const rows = document.getElementById('itgMappingRows');
            rows.replaceChildren();
            Object.keys(itgState.points).forEach((point) => {
                const input = document.getElementById('itglue_trait_' + point);
                const current = input.value.trim();
                const inType = itgState.fields.some((f) => f.name_key === current);
                const suggested = itgState.suggestions[point];
                let pick = '', note = '';
                if (!changedType && inType) { pick = current; note = 'current'; }
                else if (suggested) { pick = suggested; note = 'suggested'; }
                else if (current) { pick = current; note = 'manual key, not found in this type'; }
                else { pick = ''; note = 'no match found'; }

                const row = mapEl('div', null, 'display:grid;grid-template-columns:minmax(120px,170px) 1fr;gap:8px;align-items:center;');
                const lab = mapEl('label', itgState.points[point], 'font-size:13px;color:var(--text);font-weight:500;');
                lab.setAttribute('for', 'itgMap_' + point);
                const wrap = mapEl('div');
                const sel = mapEl('select', null, 'width:100%;');
                sel.id = 'itgMap_' + point;
                const dont = mapEl('option', "Don't sync this value");
                dont.value = '';
                sel.appendChild(dont);
                itgState.fields.forEach((f) => {
                    const o = mapEl('option', f.name + ' · ' + f.name_key + ' · ' + f.kind + (f.required ? ' · required' : '') + (f.fillable ? '' : ' (cannot be filled)'));
                    o.value = f.name_key;
                    o.disabled = !f.fillable;
                    sel.appendChild(o);
                });
                if (pick && !itgState.fields.some((f) => f.name_key === pick)) {
                    const o = mapEl('option', 'Manual key ' + pick);
                    o.value = pick;
                    sel.appendChild(o);
                }
                sel.value = pick;
                const hint = mapEl('div', note, 'font-size:11px;color:var(--dd-text-soft);margin-top:2px;');
                sel.addEventListener('change', () => { input.value = sel.value; hint.textContent = 'chosen'; itgWarn(); });
                wrap.appendChild(sel);
                wrap.appendChild(hint);
                row.appendChild(lab);
                row.appendChild(wrap);
                rows.appendChild(row);
                input.value = pick;
            });
            document.getElementById('itgMappingBox').style.display = '';
            itgWarn();
        }

        function itgWarn() {
            const out = document.getElementById('itgMappingWarnings');
            out.replaceChildren();
            const used = new Set();
            Object.keys(itgState.points).forEach((p) => used.add(document.getElementById('itglue_trait_' + p).value.trim()));
            itgState.fields.filter((f) => f.required && !used.has(f.name_key)).forEach((f) => {
                out.appendChild(mapEl('div', '"' + f.name + '" is required in IT Glue but not mapped: creating assets will be rejected.'));
            });
            Object.keys(itgState.points).forEach((p) => {
                if (!document.getElementById('itglue_trait_' + p).value.trim()) out.appendChild(mapEl('div', itgState.points[p] + ' will not be synced.'));
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const t = document.getElementById('halo_asset_type_id');
            if (t) t.addEventListener('input', () => { document.getElementById('halo_asset_type_name').value = ''; });
            const i = document.getElementById('itglue_flexible_asset_type_id');
            if (i) i.addEventListener('input', () => { document.getElementById('itglue_flexible_asset_type_name').value = ''; });
        });

        const defaultServiceCategories = ['Domain', 'Web Hosting', 'SSL'];

        function serviceCategoriesForModal() {
            const set = new Set(defaultServiceCategories);
            document.querySelectorAll('#halo-ticket-type-mapping-list .halo-ticket-mapping-row input[name*="[service_category]"]').forEach(input => {
                const value = (input.value || '').trim();
                if (value) {
                    set.add(value);
                }
            });
            return Array.from(set);
        }

        function openHaloTicketTypeMappingModal() {
            const modal = document.getElementById('haloTicketTypeMappingModal');
            const serviceSelect = document.getElementById('haloMappingServiceCategorySelect');
            serviceSelect.innerHTML = '';

            serviceCategoriesForModal().forEach(category => {
                const option = document.createElement('option');
                option.value = category;
                option.textContent = category;
                serviceSelect.appendChild(option);
            });

            const customOption = document.createElement('option');
            customOption.value = '__custom__';
            customOption.textContent = 'Other (custom)';
            serviceSelect.appendChild(customOption);

            toggleCustomServiceCategoryInput();
            modal.style.display = 'flex';
        }

        function closeHaloTicketTypeMappingModal() {
            document.getElementById('haloTicketTypeMappingModal').style.display = 'none';
            document.getElementById('haloMappingCustomServiceInput').value = '';
        }

        function toggleCustomServiceCategoryInput() {
            const value = document.getElementById('haloMappingServiceCategorySelect').value;
            const customWrap = document.getElementById('haloMappingCustomServiceWrap');
            customWrap.style.display = value === '__custom__' ? 'block' : 'none';
        }

        async function loadHaloTicketTypesForMapping() {
            const select = document.getElementById('haloMappingTicketTypeSelect');
            select.innerHTML = '<option value=\"\">Loading…</option>';

            try {
                const response = await fetch('{{ route('admin.settings.halo.ticket-types') }}');
                const data = await response.json();
                const types = Array.isArray(data.types) ? data.types : [];
                select.innerHTML = '<option value=\"\">Select Halo ticket type</option>';
                types.forEach(type => {
                    const option = document.createElement('option');
                    option.value = String(type.id);
                    option.textContent = type.name;
                    option.dataset.name = type.name;
                    select.appendChild(option);
                });
            } catch (error) {
                select.innerHTML = '<option value=\"\">Unable to load ticket types</option>';
            }
        }

        function ensureHaloMappingRowNames() {
            const rows = Array.from(document.querySelectorAll('#halo-ticket-type-mapping-list .halo-ticket-mapping-row'));
            rows.forEach((row, index) => {
                const serviceInput = row.querySelector('input[data-field=\"service_category\"], input[name*=\"[service_category]\"]');
                const ticketTypeSelect = row.querySelector('select[data-field=\"ticket_type\"], select[name*=\"[ticket_type]\"]');
                const typeIdInput = row.querySelector('input[data-field=\"halo_ticket_type_id\"], input[name*=\"[halo_ticket_type_id]\"]');
                const typeNameInput = row.querySelector('input[data-field=\"halo_ticket_type_name\"], input[name*=\"[halo_ticket_type_name]\"]');

                if (serviceInput) {
                    serviceInput.name = `halo[ticket_type_mappings][${index}][service_category]`;
                }
                if (ticketTypeSelect) {
                    ticketTypeSelect.name = `halo[ticket_type_mappings][${index}][ticket_type]`;
                }
                if (typeIdInput) {
                    typeIdInput.name = `halo[ticket_type_mappings][${index}][halo_ticket_type_id]`;
                }
                if (typeNameInput) {
                    typeNameInput.name = `halo[ticket_type_mappings][${index}][halo_ticket_type_name]`;
                }
            });

            const empty = document.getElementById('halo-ticket-type-empty');
            if (empty) {
                empty.style.display = rows.length === 0 ? 'block' : 'none';
            }
        }

        function confirmHaloTicketTypeMapping() {
            const serviceSelect = document.getElementById('haloMappingServiceCategorySelect');
            const customInput = document.getElementById('haloMappingCustomServiceInput');
            const typeSelect = document.getElementById('haloMappingTicketTypeSelect');
            const ticketClassSelect = document.getElementById('haloMappingTicketClassSelect');

            const serviceCategory = serviceSelect.value === '__custom__'
                ? customInput.value.trim()
                : serviceSelect.value.trim();

            if (!serviceCategory) {
                alert('Please choose a service category.');
                return;
            }

            if (!typeSelect.value) {
                alert('Please choose a Halo ticket type.');
                return;
            }

            const typeName = typeSelect.selectedOptions[0]?.textContent?.trim() || '';

            const template = document.getElementById('halo-ticket-type-mapping-template');
            const clone = template.content.cloneNode(true);
            clone.querySelector('[data-field=\"service_category\"]').value = serviceCategory;
            clone.querySelector('[data-field=\"ticket_type\"]').value = ticketClassSelect?.value || 'Support/Issue';
            clone.querySelector('[data-field=\"halo_ticket_type_id\"]').value = typeSelect.value;
            clone.querySelector('[data-field=\"halo_ticket_type_name\"]').value = typeName;

            document.getElementById('halo-ticket-type-mapping-list').appendChild(clone);
            ensureHaloMappingRowNames();
            closeHaloTicketTypeMappingModal();
        }

        function removeHaloTicketTypeMapping(button) {
            const row = button.closest('.halo-ticket-mapping-row');
            if (row) {
                row.remove();
                ensureHaloMappingRowNames();
            }
        }

        function openHaloStatusMappingModal() {
            const modal = document.getElementById('haloStatusMappingModal');
            modal.style.display = 'flex';
        }

        function closeHaloStatusMappingModal() {
            document.getElementById('haloStatusMappingModal').style.display = 'none';
            document.getElementById('haloMappingDomainDashStatusInput').value = '';
        }

        async function loadHaloStatusesForMapping() {
            const select = document.getElementById('haloMappingStatusSelect');
            select.innerHTML = '<option value=\"\">Loading…</option>';

            try {
                const response = await fetch('{{ route('admin.settings.halo.ticket-statuses') }}');
                const data = await response.json();
                const statuses = Array.isArray(data.statuses) ? data.statuses : [];
                select.innerHTML = '<option value=\"\">Select Halo status</option>';
                statuses.forEach(status => {
                    const option = document.createElement('option');
                    option.value = String(status.id);
                    option.textContent = status.name;
                    select.appendChild(option);
                });
            } catch (error) {
                select.innerHTML = '<option value=\"\">Unable to load statuses</option>';
            }
        }

        function ensureHaloStatusMappingRowNames() {
            const rows = Array.from(document.querySelectorAll('#halo-status-mapping-list .halo-status-mapping-row'));
            rows.forEach((row, index) => {
                const domainDashInput = row.querySelector('input[data-field=\"domaindash_status\"], input[name*=\"[domaindash_status]\"]');
                const haloStatusIdInput = row.querySelector('input[data-field=\"halo_status_id\"], input[name*=\"[halo_status_id]\"]');
                const haloStatusNameInput = row.querySelector('input[data-field=\"halo_status_name\"], input[name*=\"[halo_status_name]\"]');

                if (domainDashInput) {
                    domainDashInput.name = `halo[status_mappings][${index}][domaindash_status]`;
                }
                if (haloStatusIdInput) {
                    haloStatusIdInput.name = `halo[status_mappings][${index}][halo_status_id]`;
                }
                if (haloStatusNameInput) {
                    haloStatusNameInput.name = `halo[status_mappings][${index}][halo_status_name]`;
                }
            });

            const empty = document.getElementById('halo-status-empty');
            if (empty) {
                empty.style.display = rows.length === 0 ? 'block' : 'none';
            }
        }

        function confirmHaloStatusMapping() {
            const domainDashInput = document.getElementById('haloMappingDomainDashStatusInput');
            const statusSelect = document.getElementById('haloMappingStatusSelect');

            const domainDashStatus = domainDashInput.value.trim();
            if (!domainDashStatus) {
                alert('Please enter a DomainDash status label.');
                return;
            }

            if (!statusSelect.value) {
                alert('Please choose a Halo status.');
                return;
            }

            const haloStatusName = statusSelect.selectedOptions[0]?.textContent?.trim() || '';
            const template = document.getElementById('halo-status-mapping-template');
            const clone = template.content.cloneNode(true);
            clone.querySelector('[data-field=\"domaindash_status\"]').value = domainDashStatus;
            clone.querySelector('[data-field=\"halo_status_id\"]').value = statusSelect.value;
            clone.querySelector('[data-field=\"halo_status_name\"]').value = haloStatusName;

            document.getElementById('halo-status-mapping-list').appendChild(clone);
            ensureHaloStatusMappingRowNames();
            closeHaloStatusMappingModal();
        }

        function removeHaloStatusMapping(button) {
            const row = button.closest('.halo-status-mapping-row');
            if (row) {
                row.remove();
                ensureHaloStatusMappingRowNames();
            }
        }

        document.getElementById('haloMappingServiceCategorySelect')?.addEventListener('change', toggleCustomServiceCategoryInput);
        document.addEventListener('DOMContentLoaded', function () {
            ensureHaloMappingRowNames();
            ensureHaloStatusMappingRowNames();
        });
    </script>
<style>
    @keyframes halo-spin {
        to { transform: rotate(360deg); }
    }
</style>
@endsection
