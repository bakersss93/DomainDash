@extends('layouts.app')

@section('content')
    <div style="max-width: 960px; margin: 0 auto;">

        {{-- Header --}}
        <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">
            API Keys
        </h1>

        {{-- Create key card --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:20px 24px;margin-bottom:24px;">

            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">New API key</h2>

            <form method="POST" action="{{ route('admin.apikeys.store') }}">
                @csrf

                {{-- Name --}}
                <div style="margin-bottom:12px;">
                    <label for="name" style="display:block;font-size:14px;margin-bottom:4px;">
                        Name
                    </label>
                    <input id="name"
                           name="name"
                           type="text"
                           value="{{ old('name') }}"
                           placeholder="Key name (e.g. Monitoring integration)"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    @error('name')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Allowed IPs --}}
                <div style="margin-bottom:12px;">
                    <label for="allowed_ips" style="display:block;font-size:14px;margin-bottom:4px;">
                        Allowed IPs (optional)
                    </label>
                    <input id="allowed_ips"
                           name="allowed_ips"
                           type="text"
                           value="{{ old('allowed_ips') }}"
                           placeholder="Comma-separated list, e.g. 1.2.3.4, 5.6.7.8"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    @error('allowed_ips')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Rate limit --}}
                <div style="margin-bottom:12px;">
                    <label for="rate_limit_per_hour" style="display:block;font-size:14px;margin-bottom:4px;">
                        Rate limit per hour
                    </label>
                    <input id="rate_limit_per_hour"
                           name="rate_limit_per_hour"
                           type="number"
                           min="1"
                           value="{{ old('rate_limit_per_hour', 1000) }}"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #e5e7eb;font-size:14px;">
                    @error('rate_limit_per_hour')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Scopes --}}
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">
                        Scopes (optional)
                    </label>
                    <p style="font-size:12px;color:#9ca3af;margin-bottom:4px;">
                        Choose what this key is allowed to do. Leave blank for full access.
                    </p>

                    <div style="display:flex;flex-wrap:wrap;gap:8px;font-size:14px;">
                        @php
                            $selectedScopes = old('scopes', []);
                        @endphp
                        <label style="display:inline-flex;align-items:center;gap:6px;
                                      padding:6px 10px;border-radius:9999px;
                                      border:1px solid #e5e7eb;background:#0b1120;">
                            <input type="checkbox" name="scopes[]" value="domains.read"
                                   {{ in_array('domains.read', $selectedScopes, true) ? 'checked' : '' }}>
                            <span>Domains: read</span>
                        </label>

                        <label style="display:inline-flex;align-items:center;gap:6px;
                                      padding:6px 10px;border-radius:9999px;
                                      border:1px solid #e5e7eb;background:#0b1120;">
                            <input type="checkbox" name="scopes[]" value="domains.write"
                                   {{ in_array('domains.write', $selectedScopes, true) ? 'checked' : '' }}>
                            <span>Domains: write</span>
                        </label>

                        <label style="display:inline-flex;align-items:center;gap:6px;
                                      padding:6px 10px;border-radius:9999px;
                                      border:1px solid #e5e7eb;background:#0b1120;">
                            <input type="checkbox" name="scopes[]" value="services.read"
                                   {{ in_array('services.read', $selectedScopes, true) ? 'checked' : '' }}>
                            <span>Services: read</span>
                        </label>

                        <label style="display:inline-flex;align-items:center;gap:6px;
                                      padding:6px 10px;border-radius:9999px;
                                      border:1px solid #e5e7eb;background:#0b1120;">
                            <input type="checkbox" name="scopes[]" value="services.write"
                                   {{ in_array('services.write', $selectedScopes, true) ? 'checked' : '' }}>
                            <span>Services: write</span>
                        </label>
                    </div>

                    @error('scopes')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn-accent" style="padding:8px 14px;">
                    Generate key
                </button>
            </form>
        </div>

        {{-- Existing keys card --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:20px 24px;">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">Existing API keys</h2>

            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                <tr>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Name</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Allowed IPs</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Rate limit</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Scopes</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Last used</th>
                    <th style="text-align:right;padding:8px 6px;border-bottom:1px solid #1f2937;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($keys as $key)
                    <tr>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ $key->name }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ $key->allowed_ips ?: 'Any' }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ $key->rate_limit_per_hour ?? '—' }} / hour
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            @php
                                $scopes = $key->scopes ?? [];
                                if (is_string($scopes)) {
                                    $decoded = json_decode($scopes, true);
                                    if (is_array($decoded)) $scopes = $decoded;
                                }
                            @endphp
                            {{ $scopes ? implode(', ', $scopes) : 'All' }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ optional($key->last_used_at)->diffForHumans() ?? 'Never' }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;text-align:right;">
                            @if(method_exists($key, 'isActive') ? $key->isActive() : true)
                                <form method="POST"
                                      action="{{ route('admin.apikeys.deactivate', $key) }}"
                                      onsubmit="return confirm('Deactivate this API key? It will stop working immediately.');"
                                      style="display:inline;">
                                    @csrf
                                    <button type="submit"
                                            style="padding:6px 10px;border-radius:4px;border:1px solid #e5e7eb;
                                                   font-size:13px;background:transparent;">
                                        Deactivate
                                    </button>
                                </form>
                            @else
                                <span style="font-size:12px;color:#9ca3af;">Deactivated</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"
                            style="padding:12px 6px;text-align:center;color:#9ca3af;">
                            No API keys created yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
