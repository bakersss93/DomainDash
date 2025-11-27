@extends('layouts.app')

@section('content')
    <div style="max-width: 820px; margin: 0 auto;">

        {{-- Title --}}
        <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">
            Settings
        </h1>

        {{-- Form card --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:20px 24px;margin-bottom:24px;">

            <form method="POST"
                  action="{{ route('admin.settings.update') }}"
                  enctype="multipart/form-data">
                @csrf

                {{-- BRANDING ----------------------------------------------------- --}}
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Branding</h3>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">Primary</label>
                    <input type="color"
                           name="branding[primary]"
                           value="{{ $settings['branding']['primary'] ?? '#1f2937' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">Accent</label>
                    <input type="color"
                           name="branding[accent]"
                           value="{{ $settings['branding']['accent'] ?? '#06b6d4' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">Background</label>
                    <input type="color"
                           name="branding[bg]"
                           value="{{ $settings['branding']['bg'] ?? '#ffffff' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">Text</label>
                    <input type="color"
                           name="branding[text]"
                           value="{{ $settings['branding']['text'] ?? '#111827' }}"
                           style="width:100px;height:32px;border-radius:4px;border:1px solid #e5e7eb;">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">
                        Logo (PNG, up to ~200px wide)
                    </label>

                    @if (!empty($settings['branding']['logo']))
                        <div style="margin-bottom:8px;">
                            <strong style="font-size:14px;">Current logo:</strong><br>
                            <img src="{{ Storage::url($settings['branding']['logo']) }}"
                                 alt="Current logo"
                                 style="max-height:80px;max-width:200px;border-radius:4px;">
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

                {{-- SYNERGY WHOLESALE -------------------------------------------- --}}
                <h3 style="font-size:16px;font-weight:600;margin:16px 0 12px;">Synergy Wholesale</h3>

                <div style="margin-bottom:12px;">
                    <label for="synergy_reseller_id" style="display:block;font-size:14px;margin-bottom:4px;">
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
                    <label for="synergy_api_key" style="display:block;font-size:14px;margin-bottom:4px;">
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
                        Stored securely; value is not displayed. Click “Update key” to set a new one.
                    </small>
                </div>

                <div style="margin-bottom:20px;">
                    <label for="synergy_wsdl_path" style="display:block;font-size:14px;margin-bottom:4px;">
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

                {{-- HALO PSA ------------------------------------------------------ --}}
                <h3 style="font-size:16px;font-weight:600;margin:16px 0 12px;">HaloPSA</h3>

                <div style="margin-bottom:12px;">
                    <label for="halo_base_url" style="display:block;font-size:14px;margin-bottom:4px;">
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
                    <label for="halo_auth_server" style="display:block;font-size:14px;margin-bottom:4px;">
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
                    <label for="halo_tenant" style="display:block;font-size:14px;margin-bottom:4px;">
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
                    <label for="halo_client_id" style="display:block;font-size:14px;margin-bottom:4px;">
                        Client ID
                    </label>
                    <input id="halo_client_id"
                           type="text"
                           name="halo[client_id]"
                           value="{{ old('halo.client_id', $settings['halo']['client_id'] ?? '') }}"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                </div>

                <div style="margin-bottom:20px;">
                    <label for="halo_api_key" style="display:block;font-size:14px;margin-bottom:4px;">
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
                        Stored securely; value is not displayed. Click “Update key” to set a new one.
                    </small>
                </div>

                {{-- ITGLUE -------------------------------------------------------- --}}
                <h3 style="font-size:16px;font-weight:600;margin:16px 0 12px;">ITGlue</h3>

                <div style="margin-bottom:12px;">
                    <label for="itglue_base_url" style="display:block;font-size:14px;margin-bottom:4px;">
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

                <div style="margin-bottom:20px;">
                    <label for="itglue_api_key" style="display:block;font-size:14px;margin-bottom:4px;">
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

                {{-- SMTP ---------------------------------------------------------- --}}
                <h3 style="font-size:16px;font-weight:600;margin:16px 0 12px;">SMTP</h3>

                <div style="margin-bottom:12px;">
                    <label for="smtp_host" style="display:block;font-size:14px;margin-bottom:4px;">
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
                    <label for="smtp_port" style="display:block;font-size:14px;margin-bottom:4px;">
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

                <div style="margin-bottom:20px;">
                    <label for="smtp_from" style="display:block;font-size:14px;margin-bottom:4px;">
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

                {{-- Save button --}}
                <div style="margin-top:8px;">
                    <button type="submit" class="btn-accent" style="padding:8px 14px;">
                        Save All
                    </button>
                </div>
            </form>
        </div>

        {{-- SMTP test card ------------------------------------------------------ --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:16px 20px;">
            <form method="POST" action="{{ route('admin.settings.smtp-test') }}">
                @csrf

                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">SMTP Test</h3>

                <div style="margin-bottom:12px;">
                    <label for="smtp_test_to" style="display:block;font-size:14px;margin-bottom:4px;">
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

                <button type="submit" class="btn-accent" style="padding:8px 14px;">
                    Send Test Email
                </button>
            </form>
        </div>
    </div>
@endsection
