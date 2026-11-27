# 13 - Vocus Wholesale Service Management (WSM) integration audit

Branch `claude/eloquent-hypatia-5lio42`, HEAD `1a315da`. Read-only audit; this file is the only thing written. Method: close reading of code plus all vendor specs. `vendor/` is not installed and ext-soap is absent, so no tests were run. `php -l` was run on the client, rate limiter and both controllers (all clean). The one runtime check was a small PHP snippet that reproduces VW-02.

Spec sources (page numbers are PDF pages):
- Main = `Vocus_Wholesale_Service_Management.pdf` v8.5.0 (12 pp)
- Ex = `..._Examples.pdf` (34 pp)
- Sbx = `..._Sandbox.pdf` (15 pp)
- xlsx = Street Address Component and PostCode Standard
- WSDL/XSD
- Extra context: the repo's `vocus-api/` copies of the Data Types PDF and the TCAS catalogue. They are not among the uploads but are vendor documents. They are cited as "DataTypes" and "TCAS".

The FIBRE, DIR and OPER product catalogues are not available anywhere. Every FIBRE/DIR/OPER parameter is therefore checked only against the Examples and Data Types PDFs.

## 1. Summary

The WSM-4010 fix is complete for the code path that caused it. Every read of the stored Vocus settings now goes through `settings()`. The login, session cookie, SOAP namespaces, element order, TCAS polling and fault parsing match the specs.

No Critical issues were found. The High items are:
- a transfer order uses the wrong `OrderType` (VW-01);
- the free-text address parser corrupts addresses (VW-02);
- the sync loops are unbounded and do not stop on an authentication failure (VW-03).

The rest are Medium or Low issues:
- missing guards for an empty AccessKey (VW-04);
- failed-transaction details never shown (VW-05);
- address validation against the XSD and xlsx (VW-07, VW-08);
- a column too small for `Username` (VW-10);
- incomplete error-message mapping (VW-09).

Of the 14 findings in `02-synergy-vocus-clients.md`, VOC-01, 04, 05, 09 and 11 are fixed, and VOC-06, 08 and 14 are only partly fixed. VOC-02, 03 and 10 are also only partly fixed, and VOC-07, 12 and 13 are still open.

## 2. WSM-4010 fix: confirmation status

**Confirmed complete for the reported cause.**
- Root cause: `buildRequestXml` read `$this->config` before the lazy load, so `AccessKey` was empty.
- Fix: `buildRequestXml` (`VocusWsmClient.php:491`) now calls `$this->settings()`.
- `grep 'this->config'` finds only the constructor assignment (`:52`), the lazy loader (`:69-78`) and a comment (`:489`). Every other consumer already uses `settings()`: `environment()`, `endpointFor()`, `caBundle()`, `sessionCacheKey()`, `login()` and `resolveCertPath()`.
- AliasKey is read from the same `$settings` variable (`:495`).
- Regression test: `tests/Feature/VocusStoredSettingsTest.php` constructs the client with `config=null` and asserts both `<AccessKey>` and `<AliasKey>` appear. It could not be run here.
- Evidence that the cookie reaches Vocus: production returned WSM-4010 (access-key validation) instead of WSM-4005 (login required). The `JSESSIONID` cookie, sent through `stream_context['http']['header']`, was therefore accepted. This is an inference, not a test.

**Other ways to send an empty or wrong key or session (not yet guarded):**
1. **Undecryptable settings.** If `Setting::get('vocus')` cannot be decrypted (APP_KEY changed or corrupted), `EncryptedSettingValue::get` returns `null` and records `$decryptFailures`. `settings()` then falls back to the null-filled defaults, and the client silently sends `<AccessKey></AccessKey>` again. Nothing checks `access_key` before sending (VW-04).
2. **The AccessKey can never be cleared or replaced with blank.** `Redactor::preserveSecrets` restores the stored value for blank input. The form field shows `********` (see VW-14). This is low-risk but surprising.
3. **Stale session after certificate replacement.** The cache key hashes environment, login URL, cert path and access key, but not the cert content or password. Replacing `client.p12` with a different cert of the same name keeps the old `JSESSIONID` for up to 540 s. This is benign because the old session is still valid on Vocus's side, but it is noted.
4. **Session cookie handling is otherwise correct.** `forgetSession()` clears the shared cache key and `$this->soap`, and the retry loop rebuilds the client with a new cookie. The retry happens once, only on WSM-4005.

If WSM-4010 persists after deploying `1a315da`, the stored key is genuinely wrong or missing. WSM-4010 is not in any provided document (Main 5.1 lists no such code). Its meaning is inferred from the message text.

## 3. Findings

Severity: Critical = breaks production or violates the spec in a harmful way. High = incorrect behaviour or data in a main flow. Medium = real defect with a workaround. Low = hygiene or a documentation gap.

| ID | Sev | File:line | Issue | Spec ref | Recommended fix |
|---|---|---|---|---|---|
| VW-01 | High | `Admin/InternetController.php:465`, `:495` (`order_type` 'CHURN'); migration comment | Transfer orders send `OrderType=CHURN`. The Examples for a NETWORK-CONNECT transfer say "Since this is a TRANSFERS order, client need to specify the OrderType as TRANSFER", and the request sample sends `TRANSFER`. DataTypes lists `OrderType` values NEW, CHURN, B-CHURN, PORTIN, TRANSFER, OUTSTANDING, and says B-CHURN is DSL/Telstra specific. The XSD enum NEW/CHURN is legacy and is not enforced because `Param` is a free string. A CHURN order is likely rejected or processed as the wrong order type. | Ex p27 (4.2.4), p28; DataTypes `OrderType` | Send `TRANSFER` for the transfer flow and store `order_type='TRANSFER'`. Confirm with Vocus whether RESELLER-CONNECT churn needs a different value. |
| VW-02 | High | `Admin/InternetController.php:680-683` | `parseSimpleAddress` removes the state with `str_ireplace($m[1], '', $address)`, which replaces every substring occurrence, not just the matched word. Reproduced: `12 Hunter St, Newcastle NT 0800` becomes `12 Huer St...`; `5 Sandy Rd, Casuarina SA 5000` becomes `5 ndy Rd...`. WA, SA, NT, ACT and VIC occur inside many street and suburb names. The lookup then searches the wrong street and returns "No addresses found". | Ex p17 (4.1.1) | Remove only the matched token: `preg_replace('/\b' . preg_quote($m[1], '/') . '\b/i', '', $address, 1)`. Add unit tests for the parser. |
| VW-03 | High | `Admin/InternetController.php:95-163`, `:169-241`; `Api/InternetController.php:40-91`; `VocusRateLimiter` (4/10 s) | Sync, import and API sync call `getService` once per service, serially, inside the web request. At 4 requests per 10 s that is about 2.5 s per service: 100 services take more than 4 minutes, beyond typical php-fpm/nginx timeouts, and a half-finished sync is saved. The loop also never stops on an account-level failure (WSM-4010, 4020, 4050, or WSM-4005 after the retry). It repeats the failing call for every row and writes N warnings. Excessive polling can trigger an automatic ban. | Main p4 (rate limits); Ex p21 (4.1.6 "Excessive use of polling will trigger automatic ban") | Move sync to a queued job with per-service error recording and a progress record. Abort on account-level error codes. Cache refreshes with a minimum interval. Prefer TCAS or callbacks to blanket `getService`. |
| VW-04 | Medium | `VocusWsmClient.php:67-79`, `:477-493`; `Casts/EncryptedSettingValue.php` | No guard against an empty `access_key`. Missing, undecryptable or blank settings produce `<AccessKey></AccessKey>` and the confusing WSM-4010. `login()` already throws a clear message for a missing cert path, but the AccessKey has no equivalent. | Main p4 (AccessKey sent with every request); XSD `AccessKey` minLength 1 | In `buildRequestXml`, throw a clear exception ("Vocus Access Key is not configured") when the trimmed key is empty. Log `EncryptedSettingValue::$decryptFailures['vocus']` as an APP_KEY hint. Add a settings test for the blank case. |
| VW-05 | Medium | `Admin/InternetController.php:526-567`; `show.blade.php` poll script (~line 247) | A FAILED transaction does not show its `faultcode`/`faultmessage`. The poll JSON returns them, but the JS only reads `transaction_state`, reloads on SUCCESS/FAILED and the details are lost. They are not persisted either. WITHDRAWN does not reload. TCAS-4300 (unknown) and TCAS-4310 (expired after 7 days) show only as "Poll error" while the QUEUED banner stays forever. | Ex p4-5 (1.4); TCAS 3, 2.3 | Persist `fault_code`/`fault_message` on the service (or a transaction log). Show them in the banner. Handle WITHDRAWN. On 4300/4310, stop pending state, offer "refresh from live record" and clear the banner. |
| VW-06 | Medium | `Admin/InternetController.php:61-69`; `show.blade.php:78-82` | `show()` fetches `getService` on every page view, including right after an order is placed. The service does not exist at Vocus yet (the order is async), so "Live Service Details" shows a raw Vocus error on the redirect page. | Ex p24 (4.1.8: "This method is not for checking order progress") | Skip the live fetch while `isPending()`. Show "Awaiting Vocus provisioning". Render a friendly error. Optionally fetch live data via AJAX with a short cache. |
| VW-07 | Medium | `Admin/InternetController.php:258-267`, `:275-286`; `qualify.blade.php` (select lists) | Address validation and building do not follow the XSD or the xlsx. (a) `street_name` is allowed 50 chars but `Main.StreetName` is 1..25; `suburb` allows 50 but the limit is 40. (b) The UI sends only `Main.Unit1stNumber` and `Main.Street1stNumber` as integers: no unit type (AddressType), level (FloorType/FloorNumber), suffixes (`Unit1stSuffix`, `Street1stNumberSuffix`), range numbers or PropertyName. Units such as `2A` or `G01`, and numbers such as `12A`, are rejected or truncated. (c) The Street Type dropdown offers AVE, BLVD, CRES, LN and WAY. In the xlsx, AVE, BLVD, CRES and WAY are non-standard variants (AU Standard is blank), and LN is not a street type at all. The standard forms are AV, BVD, CR, WY and L/LANE. (d) Neither the dropdown nor the free-text parser accepts full names such as "Street", "Road" or "Crescent". (e) State is validated only as max 10, not against the list, and AAT is missing from the select. | XSD / DataTypes `Main.*`; xlsx ADDRESS COMPONENT (Street Type, State, Building Subunit) | Validate against the xlsx lists (street type, state). Cap `street_name` at 25 and `suburb` at 40. Normalise full names to abbreviations. Add unit type, suffix and level fields, and send `Main.AddressType`, `Main.Unit1stSuffix` and so on. Replace the integer-only rule with the documented types. |
| VW-08 | Medium | `qualify.blade.php` `doLookup`/`runQualify`; controller `validate()` calls (`:258`, `:308`) | Validation failures return Laravel's `{message, errors}` JSON, not `{error}`. The JS reads `data.error`, then `data.addresses`, so a 422 shows "No addresses found. Try the manual form" (or nothing for qualify). The real cause is hidden. | n/a | Handle `data.message` and `data.errors` in the JS, or return `error` from the controller. |
| VW-09 | Medium | `VocusWsmClient.php:32-39`, `:408` | `FRIENDLY_ERRORS` covers only 4005, 4020, 4050, 4080, 5010 and 5030. Other documented codes fall back to the raw `faultstring`, which the spec says is unreliable: WSM-0000, 4000, 4220, 4221, 4224, 5000, 5050, TCAS-4300 and TCAS-4310. WSM-4010 is not documented in the supplied specs at all. | Main p5 (5.1 table, "faultstring is less reliable"); TCAS 3 | Add messages for all documented codes. Add one for WSM-4010 ("Access key rejected: check the Vocus Access Key in Settings"), marked as inferred. Keep the raw text as a suffix. |
| VW-10 | Medium | `Admin/InternetController.php:130`, `:215`; `Api/InternetController.php:66`; migration `realm string(64)` | `Username` from `Get SERVICE` is stored in the `realm` column. `Username` is 2..100 chars (XSD) and the example is `000007788990011@wba.samplewsm.com.au`, i.e. a 16-char prefix plus a realm of up to 61 chars (about 77). On strict MySQL this raises "Data too long" and the per-service refresh fails. Semantically a username is not a realm. | XSD `Username` 2..100; DataTypes `Realm` 1..61; Ex p24 | Add a `username` column (string 100). Stop overwriting `realm` with `Username`. Truncate or validate before save. |
| VW-11 | Medium | `show.blade.php:128-155`; `setStatus` (`:591`) | Suspend and Resume are shown for every service, but Vocus supports them only for Layer 3 / Reseller-Connect. Cancel is shown for any status other than INACTIVE, including PROCESSING (a valid `ServiceStatus` value in DataTypes). | Ex p25 (4.1.9 "only valid for Layer 3 / Reseller-Connect") | Show Suspend/Resume only when `service_scope==='RESELLER-CONNECT'`. Block status changes while a transaction is pending or the status is PROCESSING. |
| VW-12 | Medium | `qualifyCheck` (`:306-330`); `qualify.blade.php`; `transferStore` (`:451-452`, `:479-484`) | The Network-Connect (NCL2) flow needs AggCVC and AVC IDs. The Examples qualify with `AVCID` and `CVCID` and call "the inclusion of Vocus AggregatedCVC" the biggest difference. The qualify UI never sends `AVCID`/`CVCID`, and `cvc_id`/`avc_id` are optional on transfer. | Ex p26-28 (4.2.x) | Add AVC/CVC inputs to the qualify step for NETWORK-CONNECT. Make `cvc_id` required for that scope on transfer. |
| VW-13 | Medium | `SettingsController.php:118`, `:189-198`; `VocusWsmClient.php:286-313`, `:231` | Certificate handling. (a) The spec says the cert is in "keystore format" (Main p3, Ex p7). Uploads accept only `.p12/.pfx/.keystore`; `.jks` is rejected. A JKS file is detected only if it is named `.keystore`, and then fails with a conversion instruction. (b) Nothing validates at upload time that the file parses as PKCS#12 or that the password is right (`openssl_pkcs12_read`). (c) OpenSSL 3 rejects legacy Java-exported PKCS#12 (RC2/3DES); the failure appears only at login as a cURL error. (d) Errors expose the full server path (`Vocus client certificate not found at: ...`). (e) Replacing `client.p12` with `client.pfx` leaves the old private key on disk. | Main p3-4; Ex p7 | Validate the upload with `openssl_pkcs12_read` and the supplied password. Accept `.jks` and detect it by magic bytes. Document the OpenSSL legacy-provider issue. Return a generic message to the UI and keep the path in the log. Delete the previous cert on replace. |
| VW-14 | Low | `Support/Redactor.php` (FRAGMENTS); `settings/index.blade.php:269-286`; `SettingsController.php:112-118` | `alias_key` is not recognised as a secret. It is shown in clear text in the settings HTML and written unredacted to audit diffs. The Access Key field shows `********` (correct), but the placeholder and label do not say "leave unchanged". The Settings UI has no environment selector or CA-bundle field; those can be set only through env vars (`VOCUS_ENVIRONMENT`, `VOCUS_CA_BUNDLE`). The helper text "Change to the sandbox URL during testing" is misleading, because the sandbox uses the same hostname via a hosts-file entry and needs the CA. `login_url`/`wsdl_url` accept `http://`. | Main p4; Sbx p2 | Add `alias_key` to Redactor. Add an environment select and a CA path field. Restrict the URLs with `starts_with:https://`. Fix the helper text. |
| VW-15 | Low | `VocusWsmClient.php:495-497`; settings view | AliasKey semantics are undocumented in the supplied PDFs. It appears only in the XSD (`AliasKey`, optional, max 64, after `AccessKey`). The code sends it alongside `AccessKey`, and the UI calls it an "optional secondary access key". Neither is verified. | XSD `AliasKey` | Confirm with Vocus whether it replaces or accompanies AccessKey. Until then keep it optional and unlabelled as "secondary". |
| VW-16 | Low | `VocusWsmClient.php:665-673`, `:711-714` | Dead and partly unverified code. `createDirectoryEntry()` and `getNotifications()` are never called. The Examples show NOTIFICATIONS with `StartDateTime`, the code sends `StartRecordID`, and the git history (commits `15b7de6`, `21cac2e`) says production rejected both. DIR::Create is needed for CPE/NCD ordering, so that feature is absent. No callback receiver exists (VOC-07). | Ex p22-23, p29; Main p9-10 | Either remove the dead methods or get the FIBRE catalogue and fix them. Track the callback receiver as a feature. |
| VW-17 | Low | `VocusWsmClient.php:775-818`; `InternetController.php:631` | AUTH-LOG and DISCONNECT. The code sends `PlanID=<service plan>`, `Scope=AUTH-LOG`, params `ServiceID` and `ProductID=FIBRE`. The Examples text says scope AUTH-LOG, but their sample request shows `PlanID=AUTH-LOG`, `Scope=USAGE-DAILY` (the Sandbox calls USAGE-DAILY a separate scope). The doc is self-contradictory and the examples are ADSL, not FIBRE (VOC-12 still open). When `plan_id` is null the controller sends `PlanID='STANDARD'`, which does not exist for FIBRE. | Ex p31-32; Sbx p13 | Confirm the OPER catalogue for FIBRE. Do not default the plan: refresh from Vocus first or refuse with a clear message. |
| VW-18 | Low | `show.blade.php` poll script; `pollStatus` | Polling is manual only (good: no auto-poll, no loop on auth errors). But there is no cooldown. Each click costs one TCAS call, or two on SUCCESS (TCAS plus a live `getService`). The vendor recommends overall order progress every 6-8 hours and notifications hourly. | Ex p21 (4.1.6) | Add a per-service cooldown (for example 5 minutes), and show "last checked". |
| VW-19 | Low | `importByIds` (`:169-241`); `Admin/InternetController.php:185` | Rows are created with `firstOrCreate` before any Vocus call. If the call fails (for example WSM-4010) the blank rows remain. IDs are not validated against the documented charset `[A-Za-z0-9._-]{1,30}` (DataTypes `ServiceID`). `AddressLong` is not part of the `Get SERVICE` response, so imported services have no address and sort as null in the list. | DataTypes `ServiceID`; Ex p24 | Validate the IDs. Create rows only after a successful `getService`. Derive the address another way (DirectoryID lookup). |
| VW-20 | Low | `orderStore` (`:346-420`), `transferStore` (`:436-520`) | The order is placed at Vocus before the database insert. A DB failure leaves an orphan order with no local record. The record is saved as `INACTIVE` and `QUEUED` regardless of the response, and the default list filter (ACTIVE) hides it. Errors are flashed through the same `status` key as successes. | Ex p20 (async create) | Insert first (pending), then call Vocus, then update. Use an explicit "pending" status. Use a distinct error flash. |
| VW-21 | Low | `vocus-api/` (tracked); `resolveWsdlPath` `:277-281` | The vendor documentation marked "Commercial and in confidence" is still tracked in git. The bundled WSDL/XSDs in `resources/wsdl/vocus/` are byte-identical to `vocus-api/.../Schemas/` and match the uploaded WSDL, so the duplicate is unnecessary (VOC-13 open). | Main p1 | Remove the PDFs from git. Drop the legacy WSDL fallback path. |
| VW-22 | Low | `tests/Unit/Services/VocusWsmClientTest.php`; `tests/Feature/VocusStoredSettingsTest.php` | The tests cover faults, relogin, rate limiting and transaction state only. There is no test for the XML builder (namespaces, element order, escaping), `lookupAddress`/`qualifyAddress` parsing, `login()`, address parsing, the controller flows or the settings validation. None were run here. | n/a | Add tests for each of these. Test the XML builder against the XSD sequence and `std:Param`. |
| VW-23 | Low | `VocusRateLimiter.php`; `config/services.php` | Rate limit. The limiter allows 4 requests per 10 s through the shared cache, under the documented 5 per 10 s. Login is not counted. The cache store must be shared by all hosts; a multi-host deployment would exceed the limit. The Sandbox claims a limit above 100 per 10 s, contradicting Main. The WSM-4080 retry (sleep 10 s, once) is correct. | Main p4; Sbx p3; Ex p4 | Document the shared-cache requirement. Keep 4/10 s. |

## 4. Verified correct (with spec references)

**Authentication and session**
- Login is `GET https://wsm.webservice.m2.com.au:9443/login/` with a client certificate and no AccessKey. 202 means success and anything else is a rejection. The code checks `$httpCode !== 202` (Main p4; Ex p7; Sbx p3).
- The session cookie is `JSESSIONID`, parsed from `Set-Cookie` case-insensitively and sent as a `Cookie:` header on SOAP calls (Sbx p3).
- Production SOAP endpoint is the WSDL `soap:address` `https://wsm.webservice.m2.com.au/WholesaleServiceManagement` (port 443). The sandbox uses the same hostname via a hosts-file entry (203.23.116.113) and a self-signed certificate. `ca_bundle`/`ca_bundle_path` supports this without disabling verification (Sbx p2; Main p11).
- Session lifetime: the session idles out after 600 s. The cache TTL of 540 s from login is always shorter, so a cached session is never stale. 4005 re-login happens once (Main p4; Ex p7).
- The cache key includes environment, login URL, cert path and access key. Login is serialised with `Cache::lock`.

**Request format**
- Only four operations exist (Create/Get/Set/Delete). The code uses `ucfirst(strtolower($op)).'Request'`, matching the WSDL elements. Delete is intentionally unused (Main p6-7).
- Element order matches the XSD sequence: AccessKey, AliasKey, ProductID, PlanID, Scope, Profile, ReviveAfter, Parameters. `Profile` and `ReviveAfter` are unused but supported by the XSD.
- `Parameters` is in the WSM namespace and `Param` is in the StandardDataTypes namespace (`std:Param`), as in Sbx p5 and the XSD (`elementFormDefault=qualified`). Git commit `4b96cb3` says the server confirmed this.
- `Parameters` is omitted when empty (XSD requires at least one `Param` when present). XML control characters are stripped and the rest escaped.
- Bundled WSDL/XSDs: the repo WSDL equals the uploaded WSDL (modulo CRLF) and the repo XSDs equal `vocus-api/`.

**Operations**

| Operation | Request | Spec |
|---|---|---|
| `lookupAddress` | DIR / BROADBAND, `Main.StreetName`, `Main.StreetType`, `Main.Suburb`, `Main.State`, `SearchMode=DEFAULT` | Ex p17 |
| `qualifyAddress` | FIBRE / Get / QUALIFY, `DirectoryID` (optional `AVCID`, `CVCID`) | Ex p18, p26 |
| `createDirectoryEntry` | DIR / Create / STANDARD | Ex p19 |
| `getService` | FIBRE / Get / SERVICE, `ServiceID`, no PlanID | Ex p24 |
| `orderService` | FIBRE / Create / `<scope>` with `PlanID` | Ex p20 |
| `setServiceStatus` | FIBRE / Set / STATUS, `ServiceID` + `ServiceStatus` (ACTIVE, SUSPEND, INACTIVE), no PlanID | Ex p25 |
| `pollTransaction` | TCAS / PlanID `TRANSACTION-ID` / Get / RESPONSE | Ex p4, p21 |
| `disconnectSession` | OPER / Set / DISCONNECT | Ex p32 |

- On `getService` and `setServiceStatus`, the Examples text says "scope SERVICE" for Resume while the sample uses STATUS. The code follows the samples.
- `CADate` is sent as `YYYYMMDD` (length 8).
- `Password` is limited to 2..50, `ServiceID` is limited to the documented charset, `CustomerName` to 40, `Realm` to 61 (DataTypes), and `Phone` to 10 digits.

**Responses and errors**
- `DefaultResponse` is parsed into TransactionID, ResponseType and Parameters, including a single `Param` as a non-array, repeated ids as arrays and CDATA records. `ResponseType` is only `ASYNC` or `SYNC` (XSD enum). QUEUED/PROCESSING/SUCCESS/FAILED/WITHDRAWN are `TransactionState` values (XSD, TCAS 2.3), which the code treats correctly.
- State is upper-cased (the docs show `Processing` in mixed case). SUCCESS does not imply ACTIVE: the status is re-read from the live record.
- `FaultResponse` detail is scanned recursively for `ErrorCode` and `TransactionID`. This works for either wrapper name (Main p5; Sbx p3). The user message includes the code and TransactionID, which Vocus asks for on tickets (Main p2).
- WSM-4005 triggers one relogin and WSM-4080 one 10 s wait and retry (Main p4-5).

**Security**
- AccessKey, AliasKey and the cert password do not appear in logs. `Log::error` records operation, product, error code, TransactionID and message only. `trace` is false, so request XML is not retained. Audit diffs redact `access_key` and `cert_password`.
- Settings are encrypted at rest. The cert is stored under the `local` disk (`storage/app/private/vocus/`), outside `public/`.
- TLS verification is on for both the cURL login (`VERIFYPEER` true, `VERIFYHOST` 2) and the SOAP stream context (`verify_peer`, `verify_peer_name`). No insecure toggle exists.
- All output in the Internet views is escaped (`{{ }}`, `textContent`, `qEsc`).

## 5. Status of the prior VOC-* findings

| Prior | Status | Note |
|---|---|---|
| VOC-01 fault parsing | Fixed | `extractFault`, `scanFaultNode` and the typed exception are tested. |
| VOC-02 rate limiting | Partly fixed | Limiter plus retry on 4080 added. Sync is still an in-request loop (VW-03). |
| VOC-03 sandbox/environment | Partly fixed | Config, CA bundle and environment exist in code but not in the Settings UI (VW-14). |
| VOC-04 session cache | Fixed | Scoped key, lock, lazy login. |
| VOC-05 timeouts / WSDL cache | Fixed | Connect and request timeouts set, disk cache for the WSDL. The live fetch on `show()` is still synchronous (VW-06). |
| VOC-06 async state | Partly fixed | Normalised and status is re-read. The fault details are still not shown (VW-05). |
| VOC-07 callback receiver | Open | Absent (VW-16). |
| VOC-08 input validation | Partly fixed | Order fields are fixed. Address fields are still wrong (VW-07). |
| VOC-09 XML hygiene | Fixed | Control characters are stripped. |
| VOC-10 credential storage | Partly fixed | Encrypted and masked, but `alias_key` is exposed and there is no cert-expiry reminder (VW-14). |
| VOC-11 IP allow-list message | Fixed | The login error mentions the 2-IP allow-list. |
| VOC-12 AUTH-LOG form | Open | Still ambiguous (VW-17). |
| VOC-13 tracked vendor docs | Open | Still tracked (VW-21). |
| VOC-14 friendly errors | Partly fixed | Six codes mapped (VW-09). |

## 6. Unverified items

- **Tests not run.** Nothing in `tests/` was executed because `vendor/` and ext-soap are missing, and the code was never exercised against Vocus from here. The only execution was the VW-02 snippet.
- **PHP SoapClient `detail` shape.** The fault scanner is generic, but the exact decoded shape was not observed.
- **Cookie header via the stream context.** Inferred to work from the 4010-versus-4005 evidence. `__setCookie()` is the documented method and could replace it for certainty.
- **FIBRE, DIR and OPER catalogues missing.** Parameter names, required/optional status and enums for FIBRE/DIR/OPER (OrderType values, CustomerName length, ServiceType, `Main.Postcode` vs `Main.PostCode`, NOTIFICATIONS parameters, AUTH-LOG form) are checked only against the Examples and Data Types PDFs. The Examples lookup sample writes `Main.Postcode`, while the XSD, DataTypes, create sample and the code use `Main.PostCode`. Param ids are case-sensitive (Main p6), so confirm which DIR::Get accepts.
- **Scope for NCL2 orders.** The Examples plan table lists NETWORK-CONNECT, but the NCL2 sample sends `Scope=RESELLER-CONNECT` (Ex p16, p27). The UI offers both.
- **AliasKey and WSM-4010 meaning** (VW-15, VW-09): not defined in the supplied specs.
- **QUALIFY FAIL form.** Only a PASS response is documented (Ex p18). The documented form of a failed qualification is unknown.
- **Whether `AccessKey` with a different certificate** (up to 10 keys per cert, 3 in test, Main p3-4) needs per-key sessions: not stated.
- **The rate limit applies "at the overall web service level"** (Main p4): whether that is per certificate, per account or global is not stated.
- **Authorisation.** I did not verify authorisation on the Internet routes beyond the admin route group, and did not review the API-key middleware.
