# 14 - Vocus catalogue verification

Branch `claude/eloquent-hypatia-5lio42`. Sources (all supplied by the user): Product Catalogues FIBRE v8.30.0 (FIB), TCAS, CPE, OPER, DIR, NETWORK, MOBILE (skimmed, not used) and the Vocus Data Types Definition (DT, source of truth for param names, types, lengths, enums). Page refs use the catalogue section numbers. Nothing could be run here (no `vendor/`, no ext-soap): changes were checked with `php -l` and `node --check` on the extracted inline scripts; the PHPUnit tests were updated for the user to run.

## Summary of corrections

1. A NEW order has **no port param**; the UNI-D port is auto-selected. `DataPortID = {ntd}-UNID-{port}` was wrong everywhere. A transfer sends **`DataPortNumber`** (integer 1-4).
2. "New line" is `CopperPairID=NEW` (FTTB/N, FTTC) or `NTDID=NEW` (HFC); FTTP has nothing to send. Appointments are auto-booked, with no Create params.
3. QUALIFY returns `ServiceType=FIBRE` but Create needs `FTTP`: the old code would have sent `FIBRE`.
4. FTTB/FTTN NEW orders require `VoicebandContinuity` and `CentralSplitter`, which were never sent. `CarrierID` is sent with continuity.
5. `Password` is mandatory on Reseller-Connect Create/Transfer (the transfer form had no password field). Maximum is 30, not 50. Network-Connect has no Username/Password/AuthType/IPAssignment.
6. `ServiceLevel` values are `...-24x7`, not `-24/7`.
7. Transfers: `AVCID` is compulsory for `TRANSFER` (since April 2025), not for `OUTSTANDING`; `OrderType` OUTSTANDING (11 chars) did not fit the `order_type` column (10); `CADate` must be within 45 days.
8. `TC2Speed` is a Traffic Class 2 speed, not a line rate; it is no longer a download fallback.
9. OPER: AUTH-LOG/DISCONNECT use the service's own PlanID (settled), are Reseller-Connect only, and the response record is `AuthLogRecord` (the Data Types still say `AuthUsageRecord`; both are read).
10. DIR BROADBAND: `Main.Postcode` (lower c), `Main.Unit1stType` (not `Main.AddressType`), no PropertyName/Unit2nd/Street2nd params.

## 1. NTD / UNI-D port selection

- **Catalogue**: FIB 4.1.1.1 and 4.1.2.1 tables: UNI-D Port, NEW: "Auto selected. A free port is required." TRANSFER / OUTSTANDING: "Client must provide port to transfer". FIB 4.1.1.3 / 4.1.2.3 request tables: `DataPortNumber` 1..1 for FTTP and FW, "UNI-D Port number to transfer. Range 1 - 4"; DT `DataPortNumber integer`. `DataPortID` (DT: "NBN Data Port ID eg. 1-UNID-2") is a *response* field (FIB 4.1.x response, 4.2.2 Get SERVICE). FIB appendix 6.1 (consolidated) still lists a request `DataPortID` "UNI-D port to use for transfer": it contradicts the per-scope tables and is treated as an editorial leftover.
- **Was**: `DataPortID` = `{ntd_index}-UNID-{port}` sent on every NTD order, choice mandatory when ports were listed (config `order_port_param`, `order_port_format`, `send_port_param`).
- **Changed**: `VocusLineSelection` sends no port on NEW. Transfer sends `DataPortNumber` (config `services.vocus.transfer_port_param`, default `DataPortNumber`; override kept, harmless). HFC sends `NTDID`; FTTP sends `NTDID` when an NTD is chosen (NEW: "on premises with multiple NTD"; OUTSTANDING multi-NTD). A transfer may select an **in-use** port (that is the service being moved; the old UI only offered free ports). The three removed config keys are gone. `uni_port` is stored only for transfers; on a NEW order it is filled later from `DataPortID`.
- **Still unverified**: which NTD id applies when several NTDs exist and the customer's port is unknown.

## 2. "Order a new line"

- **Catalogue**: `CopperPairID` "Send NEW to order new Copper Pair with NBNco" and `VoicebandContinuity` "Send FALSE when ordering new Copper Pair" (FIB 4.1.1.2 / 4.1.2.2, FTTB/N and FTTC). HFC `NTDID`: SC21/22 "NEW to order a new line with NTD with Professional Install", SC23 "NEW to order a new NTD", SC24 "NEW to order a new line & NTD with Professional Install". FTTP: new AVC, no param. NBN installs are chosen with `NBNCPEPlanID` (appendix 6.4). "Initial technician appointment will be created on first available basis and returned in response params. Client can alter the initial appointment date using FIBRE::Set scope APPOINTMENT-UPDATE" (FIB 4.1 intro). Create has **no appointment params**.
- **Was**: new line omitted everything (`VOCUS_NEW_LINE_PARAM`).
- **Changed**: copper new = `CopperPairID=NEW` (+ `VoicebandContinuity=FALSE` on FTTB/N); HFC new = `NTDID=NEW` + `NBNCPEPlanID=HFCNTDNBNPROFINSTALL`; FTTP/Wireless new sends nothing. `CopperPairID` is now compulsory for FTTB/FTTN/FTTC (it used to be optional when no pairs were listed). New optional "NBN installation option" select (`nbn_cpe_plan_id`, appendix 6.4 values per technology, validated; `-REPLACE` needs the NTD). The `VOCUS_NEW_LINE_*` config is removed. The appointment note now says it is auto-booked.
- **Not implemented**: self-install / warehouse-shipped NTD or NCD (`HFCNTDWHS`, `FTTCNCDRC`, `FTTCNCDNC`, `FTTPNTD...` shipped) need `CPEDirectoryID` from DIR::Create STANDARD plus follow-up of the shipment (item 10). `APPOINTMENT-AVAILABILITY` / `APPOINTMENT-UPDATE` / `APPOINTMENT-CONTACT` / `NBN-CPE-WORKFORCE` UI is not built.

## 3. Username / Realm / Password

- **Catalogue**: FIB 4.1.1.2: `Realm` 1..1 (both scopes); `Username` 0..1 "PPPoE username, when not provided Vocus will auto generate the username" (RESELLER-CONNECT only; "Usernames are auto-generated for RESELLER-CONNECT", FIB 4.1 table); `Password` 1..1 (RC); NETWORK-CONNECT lists neither Username nor Password. DT `Username` string(2..64) "including the realm. For example: user_id@realm.com. User_id must be between 2 to 22 characters"; DT `Password` string(2..30); DT `Realm` string(1..61).
- **Was**: `Username = user@realm` (right) with the unique part 4-22 (portal rule), blank omitted (right), Password optional 2..50 (wrong), sent on any scope.
- **Changed**: unique part 2-22 (`USERNAME_REGEX`); Password **required** on Reseller-Connect Create *and* Transfer, 2-30 (`PASSWORD_REGEX`), with a Generate button (client-side, never stored); Layer 2 (Network-Connect scope or an `NCL2` plan) sends Realm only (username/password/AuthType/IPAssignment are prohibited server-side and hidden in the form); IPoE omits Username.
- **Unverified**: the password charset (A-z 0-9 - . is the portal rule; the catalogue lists no charset).

## 4. ServiceID, ServiceType, LocationReference, CustomerName/Phone, ServiceLevel

- **ServiceID**: DT string(1..30) `a-z A-Z 0-9 - _ .`, unique, client supplied. Already correct (`DD-<12 digits>-<4>`, 21 chars).
- **ServiceType (Create)**: FIB 4.1.1.2: `FTTP, WIRELESS, FTTB, FTTN, HFC, FTTC`. QUALIFY response (FIB 4.2.1) and DT: `FIBRE, WIRELESS, FTTB, FTTN, HFC, FTTC` or N/A. **Fixed**: `VocusOptions::createServiceType()` maps FIBRE to FTTP (and Fixed Wireless to WIRELESS) in the qualify JSON, the order links, and server-side before validation (`Rule::in(SERVICE_TYPES)`). The technology group now also recognises FIBRE. The index filter lists WIRELESS, not FIXED-WIRELESS.
- **LocationReference**: DT string(1..30), FIB "Postcode where the service is located". Already correct (4 digits).
- **CustomerName**: DT string(1..40) "full name including title". **Phone**: DT integer(10). Kept the stricter portal rules (first+last, 4-39; 10 digits): already correct and within limits.
- **ServiceLevel**: DT values `STANDARD, ENHANCED4, ENHANCED6, ENHANCED8, ENHANCED12` and the same with **`-24x7`**. **Fixed** (`-24/7` was wrong); constant moved to `VocusOptions::SERVICE_LEVELS`. FIB appendix 6.3: plans such as Home Fast / FW are "STANDARD only"; that depends on the plan and cannot be derived, so the form does not filter it.
- **Reference** (DT string 2..30 `a-z A-Z 0-9 - _ .`) and **Label** (string 1..50): `Reference` added; `Label` not added (free text, not requested).

## 5. Fields shown in the Vocus portal

| Portal field | Catalogue param | Done |
|---|---|---|
| Authentication Type | `AuthType` `PPPoE`/`IPoE` (RC only; default PPPoE; DT also lists `NA`, read-only). Set scope `AUTH-TYPE` changes it later and resets the IP to CGNAT | Select on the order and transfer forms, validated, stored (`auth_type`) |
| IP Type (Static IP / CGNAT) | `IPAssignment` `CGNAT`/`STATIC`/`DYNAMIC` (RC only; default CGNAT; DT also lists TEMPORARY, STICKY, TEMPORARY-EXTENDED, OTHER as read values). Set scope `IP-ASSIGNMENT` changes it later (portal only for "support related" changes) | Select, validated, stored (`ip_assignment`) |
| IP Address | `IPAddress` (+ `IPv6Address`, `IPv6PrefixList`) are **response** params (4.1.1.2, 4.2.2). The consolidated table 6.1 lists them as request params: not offered | Read from TCAS/Get SERVICE, stored (`ip_address`), shown |
| Customer Reference | `Reference` | Text field, validated, stored (`reference`) |
| Mapping (Default / Tagged / DSCP) | No param: "WSM PlanID will be used to differentiate the models" (FIB 4.1 table, 4.1.2). TrafficClass1/2 exist for Tagged/DSCP on Network-Connect (appendix 6.3) | Chosen by the plan; TrafficClass not offered |
| UNI-D port | `DataPortNumber` (transfer) / `DataPortID` (response) | Item 1 |
| Central splitter | `CentralSplitter` TRUE/FALSE, **FTTB/FTTN NEW only, 1..1** ("Not available" on transfer) | Select on FTTB/FTTN new orders, stored (`central_splitter`) |

Migration `2026_11_27_000001_add_catalogue_fields_to_internet_services.php` adds `auth_type`, `ip_assignment`, `ip_address`, `reference`, `central_splitter` and widens `order_type` to 20.

## 6. Scopes, AVC/CVC, OrderType

- **Scopes** (FIB 4.1): `RESELLER-CONNECT` = Layer 3 incl. PPPoE/IPoE, IP assignment, optional TR-69 modem; `NETWORK-CONNECT` = Layer 2, NCL2 and NCL2 Plus, no RADIUS entry, "IP Assignments: Not valid". Per-scope request params are applied as above (RC: Username/Password/AuthType/IPAssignment/CPEPlanID; NC: CVCID/CTAG/RSPSTAG/RSPCTAG/TrafficClass1-2). The older Examples NCL2 sample orders NCL2 on scope RC with plan `NCL2-...`; the code keeps treating an `NCL2` plan as Layer 2.
- **OrderType** (FIB 6.1): `NEW` (default), `TRANSFER`, `OUTSTANDING`. DT also lists CHURN, B-CHURN (DSL/Telstra only), PORTIN: none apply to FIBRE, so CHURN stays gone. NEW is now sent explicitly. The transfer form has a Transfer type select.
- **TRANSFER vs OUTSTANDING**: TRANSFER needs `AVCID` ("OrderType TRANSFERS required AVCID param for compulsory verification"; "Currently optional. Compulsory by April 2025"), OUTSTANDING does not. `avc_id` is therefore required for TRANSFER. A transfer with a CPE is paused until `FIBRE::Set COMMAND RESUME` (not built).
- **CADate**: required, YYYYMMDD, "from todays date and cannot be older than 45 days": validated.
- **CVCID**: compulsory for **NCL2 NEW and transfer** (the old code only required it for transfers and sent nothing on NEW). `CTAG` optional (0 = auto), `RSPSTAG` compulsory for NCL2 Plus (required in the form when the plan code contains PLUS: a heuristic, as the catalogue only says "Plus PlanID"), `RSPCTAG` optional. QUALIFY accepts `CVCID`, `AVCID`, `CarrierID`, `ServiceType`, `ProductCode` (client + endpoint); no UI inputs for them were added.
- **NETWORK catalogue**: CVCs are created with `NETWORK::Create AGGCVC` (PortIDPrimary, STAG, CVCState, TrafficClass1/2/4, Description) and listed with `NETWORK::Get AGGCVC` (optional CVCID or State filter; NCL2 customers only). Added `VocusWsmClient::getAggregatedCvcs()`; no UI (the CVC ID is still typed). Not built: creating or deleting Aggregated CVCs, RSP STAG ports, AGGCVC-SPEED.

## 7. QUALIFY

- **Request** (FIB 4.2.1): `DirectoryID` 1..1, `CarrierID` (FTTB/N voice continuity, "Required for FTTC"), `CVCID` (NCL2), `AVCID` (transfer verification), `ServiceType` (Fibre On Demand / Primary / Original), `ProductCode` (RESELLER-CONNECT default, NETWORK-CONNECT, RESELLER-CONNECT-PLUS, NETWORK-CONNECT-PLUS). `qualifyAddress()` now accepts all of them.
- **Response**: `Result` = `PASS` / `REJECTED` / `REJECTED-CVC` / `MANUAL` (the badge now shows the real value and its meaning, not a blanket FAIL); `ServiceType`; `ServiceClass` (table 6.2, now the exact Vocus table instead of the "NBN convention" ranges); `AlternativeTechnology`, `PrimaryTechnology`, `OriginalTechnology`, `AVCIDMatch`, `AVCIDTarget`, `CurrentEPID`, `DataPort` ("TRUE = a data port is still available at the premise"), `NTDRecord*`, `NBNPortRecord*`, `CopperPairRecord*`, `ActivationDate`, `HFCSelfInstall`, `NBNFeatureRecord`, `NBNCOATRecord`, `Zone`, TC1-TC4 availability, `AvailableCTAG`, `STAG`, `RSPSTAG`, `DevelopmentCharge`. The normaliser reads the ones DomainDash uses and now also `DataPort`, `AlternativeTechnology`, `AVCIDMatch/Target`, `CurrentEPID`, `ActivationDate`.
- **Record fields** (DT): CopperPairRecord `CopperPairID, CopperPairStatus (ACTIVE|INACTIVE|ULL|N/A), NBNServiceStatus, ServiceClass, POTSInterconnect, POTSMatch, TC2Speed, UploadSpeed, DownloadSpeed, NetworkCoExist, ExtraCharge, RemainingUpload/Download, MAC`; NTDRecord `NTDID, NTDType, NTDVersion, Battery, BatteryMonitor, BatteryDate, UploadSpeed, DownloadSpeed, RemainingUpload/Download, MAC`; NBNPortRecord `NTDID, NTDVersion, PortNumber, PortName, Available ("whether a port is free or not"), PortType DATA|VOICE, EPID, MAC`. These match the parser. The exact `Available` spelling is still not stated (TRUE/FALSE assumed, others tolerated).
- **Speeds**: copper `DownloadSpeed` / `UploadSpeed` are "Minimum/Maximum theoretical speed in **Mbps**, e.g. 90-100" so the upper figure is the cap: unchanged. **`TC2Speed` is "Traffic Class 2 supported speed"**: it is not a line rate, so it is no longer used as a download fallback (test updated). **NTDRecord `UploadSpeed`/`DownloadSpeed` have no description at all**: still used as a cap (the previous behaviour) but behind `services.vocus.ntd_speed_is_cap` (default true; set false to stop filtering plans by NTD speed). **Unverified.**

## 8. Get SERVICE, Set operations

- **Get SERVICE response** (FIB 4.2.2): ServiceID, ServiceScope, Username, Password (not for NC), **AuthType**, ServiceType, ServiceStatus, PlanID, OrderType, TrafficClass1/2, CustomerName, Phone, CopperPairID, VoicebandContinuity, CarrierID, Battery, BatteryMonitor, ServiceLevel, CentralSplitter, NBNInstanceID, NTDID, NBNCRD, DataPortID, CTAG, STAG, AVCID, CVCID, RSPCTAG, RSPSTAG, CSA, AnniversaryDay, IPAssignment, IPAddress, IPv6Address, IPv6PrefixList, DirectoryID, CPEDirectoryID, CPEServiceID, CPEPlanID, NBNCPEServiceID, NBNCPEPlanID, LocationReference, Reference, BundleReference, BillingProviderID, StabilityProfile, NBN outage records, Label. **The speculative keys `AuthenticationType`, `IPType`, `UNIPort`, `UNIDPort`, `Mapping`, `CustomerReference`, `RoutedSubnet`, `PortName`, `DataPortNumber`, `AppointmentDate`, `AppointmentStatus` and `AddressLong` do not exist in the catalogue** and were removed from `LIVE_FIELD_MAP` and the show page labels. `LIVE_FIELD_MAP` now also maps AuthType, IPAssignment, IPAddress, Reference, CentralSplitter. The show page labels the full documented list; unknown extras still render generically; Password is never shown.
- **Set scopes**: STATUS (`ACTIVE`/`INACTIVE`/`SUSPEND`, "cannot be deactivated when there is an active appointment"; reactivation from INACTIVE only for some plans), PLAN, TRAFFIC-CLASS, APPOINTMENT-UPDATE / -CONTACT, SERVICE (Password, AnniversaryDay, Reference), CPE, NBN-CPE-REPLACE / -WORKFORCE / -AMEND, COMMAND, CVC-MOVE, CTAG, STABILITY-PROFILE, IP-ASSIGNMENT, LABEL, AUTH-TYPE, RSP-STAG-MOVE, RSP-CTAG. DomainDash implements STATUS only.
- **Suspend/Resume (VW-11)**: the FIBRE catalogue does not restrict STATUS by layer ("Some PlanIDs may have restrictions"); the Examples (4.1.9) say Layer 3 only, and Network-Connect has no RADIUS entry. Layer 2 stays blocked in the UI and server: unchanged, **still partly unverified**. Deactivate (INACTIVE) cancels a bundled modem irreversibly (FIB 4.1.1.4).
- **Delete**: `Delete SERVICE` (INACTIVE only) and `Delete PROVISIONING` (withdraw an order by TransactionID) exist; neither is built.

## 8b. OPER: AUTH-LOG and DISCONNECT (settles VOC-12 / VW-17)

- **Catalogue** (OPER 2.1, 4.2.3, 4.3.1): ProductID `OPER`, "will use the same PlanID as the product that will be manipulated", so PlanID is the service's own plan. AUTH-LOG request: `ServiceID`, `ProductID` (value `FIBRE`), response `AuthLogRecord*` (up to 2 days). DISCONNECT (Set): `ServiceID`, `ProductID`, optional `SessionID` (default: all sessions; takes up to 5 minutes). Both supported for **FIBRE RESELLER-CONNECT only** (and MOBILE DATA-HOSTED). Also available: USAGE-DAILY (`Year`, `Month`), USAGE-HOURLY (`Date`), SESSION-LOG (`StartDate`, `EndDate`), ONLINE-SESSION. Errors OPER-2200 (invalid username/password or status), OPER-5555 (not supported).
- **Was**: right shape; plan default unclear; response read as `AuthUsageRecord`; shown for Layer 2 too.
- **Changed**: plan comment settled, `getAuthLog` reads `AuthLogRecord` (and `AuthUsageRecord`, as DT and the Examples still name it), `disconnectSession` takes an optional `SessionID`, diagnostics are refused/hidden for Layer 2, OPER friendly error codes added.

## 8c. DIR (address lookup, directory create)

- **DIR::Get BROADBAND** (DIR 5.2): `Main.LotNumber, Unit1stNumber, Unit1stSuffix, Unit1stType, FloorNumber, FloorSuffix, FloorType, Street1stNumber, Street1stNumberSuffix, StreetName (1..1), StreetType (1..1), StreetSuffix, Suburb (1..1), State (1..1), Postcode (0..1)`. Response `FibreAddressRecord 1..*` = CDATA `BroadbandAddressRecord` (DirectoryID, Carrier NBN|OPTICOMM|FUJITSU|PLACESVIC|TELSTRA, AddressLong). Scope ignored.
- **Settled**: `Main.Postcode` (lower c) for Get (DIR::Create STANDARD uses `Main.PostCode`); the unit type param is `Main.Unit1stType`, **not** `Main.AddressType` (that is the DIR::Create name); no PropertyName, Unit2nd or Street2nd params.
- **Changed**: `VocusWsmClient::broadbandLookupParams()` maps AddressType to Unit1stType, PostCode to Postcode and drops undocumented keys; the "alternate PostCode spelling" retry and `lookup_postcode_param` config are removed (3 attempts instead of 4: street level, with number/unit, without postcode). `SearchMode=DEFAULT` is kept because the Examples sample sends it, but it is **not in the DIR catalogue** (unverified; no other value is known). Error codes DIR-1181 etc. added.
- **DIR::Create STANDARD** (needed only for CPE/NCD shipping): `FirstName`, `LastName`, `Main.Street1stNumber/StreetName/StreetType/Suburb/State/PostCode/Country` (all 1..1), `Normal.ContactFirstName/LastName/Phone` and `Emergency.*` (1..1); an unused entry is purged after 24 hours. `createDirectoryEntry()` already passes a param array; not wired to any UI.

## 9. TCAS

- **Catalogue**: PlanID `TRANSACTION-ID`, scopes RESPONSE / REQUEST / STEPS; states QUEUED, PROCESSING, SUCCESS, FAILED, WITHDRAWN (transitions QUEUED to PROCESSING to FAILED/SUCCESS/WITHDRAWN); FAILED params `faultcode` / `faultmessage`; transactions kept 7 days; errors TCAS-4300 (unknown), TCAS-4310 (expired after 7 days), 4220, 4222, 4223, 5030, 5050.
- **Was**: already correct (state handling, fault capture, 4300/4310 clearing the pending state, friendly messages).
- **Changed**: on SUCCESS the Create response params (`AVCID, CVCID, NTDID, DataPortID, NBNInstanceID, BillingProviderID, IPAssignment, IPAddress ...`) are applied to the service record before the live refresh. Friendly texts for all FIBRE-, DIR-, OPER-, NETWORK- codes added (FIB 3, DIR 3, OPER 3, NETWORK 3), with Vocus's own message appended for the 4220 / 2110 / 2170 families. `FIBRE-5010` (partially completed) points to TCAS `STEPS` (not built).
- Note: a NOTIFICATIONS ConversationRecord `COMPLETE` does not mean the order is complete: only TCAS RESPONSE `SUCCESS` does.

## 10. CPE / NCD shipment

- **Catalogue**: FIB 4.1.1.2 flow: DIR::Create STANDARD (`CPEDirectoryID`, shipping address) then FIBRE::Create with `CPEDirectoryID`, `CPEPlanID` ("provided separately" by Vocus, not in any catalogue we hold) and `NBNCPEPlanID` (appendix 6.4); follow up by TCAS RESPONSE about once a day for the shipment tracking number, then CPE::Get SERVICE (`Consignment`, `ShipmentCompany`, `DispatchRequest/Response`, `SyncStatus`). CPE::Create scopes DEFAULT/HDM/DISPATCH need a full delivery address.
- **Not implemented, deliberately**: it needs a DIR entry, a CPEPlanID DomainDash cannot know, and a shipment-tracking follow-up; "clearly documented and simple" is not met. Only NBN professional-install options (no shipping) are offered. Charges may apply to `NBNCPEPlanID`.

## 11. NOTIFICATIONS

- **Catalogue** (FIB 4.2.6): request `StartDateTime` (AEST `YYYYMMDDHHMMSS`) **or** `StartRecordID`, optional `MaxRecordCount` (default 20, max 100); response `MasterConversationRecord*`, `MasterAppointmentRecord*`, `LastRecordID`, `HasMore`. (The table says "LastID" in prose and `LastRecordID` as the param.) Pushed events (FIB 5): NBN-UNPLANNED-OUTAGE / NBN-PLANNED-OUTAGE, CHURN-LOSS, POI-STATUS, read with TCAS RESPONSE.
- **Changed**: `getNotifications($startDateTime, $startRecordId, $max)` follows that shape and returns parsed records, `last_record_id` and `has_more`; the "unverified" hedge is gone. It is still unused by the app (no callback receiver, VOC-07).

## Still unverified

- `SearchMode=DEFAULT` on DIR lookup (sample only).
- NTDRecord speed semantics (cap switch provided).
- Whether Suspend/Resume is truly refused for Network-Connect (Examples only).
- Which of `DataPortNumber` (per-scope) and `DataPortID` (appendix 6.1) Vocus really reads on transfer; config override kept.
- The Password charset and `Available` value spelling.
- NCL2 Plus detection by plan code (`PLUS`) and the RC scope with an NCL2 plan (Examples sample).
- `NBNCPEPlanID` default behaviour when omitted ("Vocus might enforce a default").
- Nothing was run against Vocus or PHPUnit from this environment.

## Not implemented (documented, out of scope)

CPE/NCD/modem shipment, appointment availability/update/contact, `Set PLAN` / `TRAFFIC-CLASS` / `AUTH-TYPE` / `IP-ASSIGNMENT` / `SERVICE` / `LABEL`, `Delete PROVISIONING`, `NETWORK` CVC management, USAGE-/SESSION-LOG scopes, a notification/callback receiver, MOBILE (not used by DomainDash).

## Files

Code: `app/Services/Vocus/{VocusLineSelection,VocusOptions,VocusQualification,VocusWsmClient}.php`, `app/Http/Controllers/Admin/InternetController.php`, `app/Models/InternetService.php`, `config/services.php`, views `resources/views/admin/services/internet/{_order-fields,_line-selector,_line-list,_line-extras,qualify,show,index}.blade.php`, migration `2026_11_27_000001_add_catalogue_fields_to_internet_services.php`. Tests: `tests/Feature/Vocus{LineSelection,OrderingUx,Internet,PlanSpeed}Test.php`, `tests/Unit/Services/VocusWsmClientTest.php`.
