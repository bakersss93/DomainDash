{{-- Domain / configuration-item sync dialogs (opened from the Sync tabs). Client and organisation mapping moved to the mapping tables. --}}
    <div id="haloSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:var(--dd-backdrop);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;max-width:1200px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:20px 24px;border-bottom:1px solid var(--border-subtle);display:flex;justify-content:space-between;align-items:center;">
                <h2 style="font-size:20px;font-weight:700;margin:0;color:var(--text);display:flex;align-items:center;gap:8px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync domains to HaloPSA</h2>
                <button onclick="closeHaloSyncModal()" style="background:none;border:none;color:var(--dd-text-soft);font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                @include('admin.sync._worker-banner')
                <div id="haloDomainSyncContent" style="display:block;">
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
                <h2 style="font-size:20px;font-weight:700;margin:0;color:var(--text);display:flex;align-items:center;gap:8px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync domains to IT Glue</h2>
                <button onclick="closeItGlueSyncModal()" style="background:none;border:none;color:var(--dd-text-soft);font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                @include('admin.sync._worker-banner')
                <div id="itglueConfigSyncContent" style="display:block;">
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

    {{-- Generic asset sync dialog: internet services / hosting / SSL certificates to HaloPSA or IT Glue (opened from the Sync tabs). --}}
    <div id="assetSyncModal" role="dialog" aria-modal="true" aria-labelledby="assetSyncTitle" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:var(--dd-backdrop);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--dd-surface);border:1px solid var(--dd-border);border-radius:14px;max-width:1200px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="padding:20px 24px;border-bottom:1px solid var(--dd-border);display:flex;justify-content:space-between;align-items:center;">
                <h2 id="assetSyncTitle" style="font-size:20px;font-weight:700;margin:0;color:var(--dd-text);">Sync assets</h2>
                <button type="button" onclick="closeAssetSyncModal()" aria-label="Close" style="background:none;border:none;color:var(--dd-text-soft);font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
            </div>
            <div style="padding:24px;overflow-y:auto;flex:1;">
                @include('admin.sync._worker-banner')
                <div style="margin-bottom:16px;display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="loadAssetList()" class="btn-accent" style="padding:8px 16px;font-size:14px;">Refresh List</button>
                    <button type="button" onclick="syncAssetSelected()" class="btn-accent" style="padding:8px 16px;font-size:14px;">Sync Selected</button>
                </div>
                <div id="assetSyncRunPanel" aria-live="polite"></div>
                <div id="assetSyncList" style="background:var(--dd-surface-soft);border:1px solid var(--dd-border);border-radius:10px;padding:16px;"></div>
                <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:12px;">
                    <button type="button" onclick="closeAssetSyncModal()" class="ui-btn ui-btn--ghost">Close</button>
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
