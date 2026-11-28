---
title: "Vocus WSM: sandbox, production and the certification checklist"
summary: Test in the Vocus sandbox, record a full journey, send the evidence to Vocus and then switch to production.
category: integrations
order: 80
audience: integration
related: [vocus-wsm-setup, vocus-plans-and-ordering, audit-logs-and-retention]
---

Vocus reviews your sandbox transactions and grants production access **per API method**. {company} records every Vocus call so you can show Vocus what you did.

**Where:** **Admin > Settings > Vocus Wholesale > Sandbox**.

## Sandbox versus production

| | Production | Sandbox |
|---|---|---|
| Purpose | Real services and real orders. | Safe testing. Sandbox access is granted by Vocus. |
| Endpoint | The **SOAP endpoint URL** on the Connection tab. | Set by the environment, using the sandbox IP below. |
| Certificate trust | Public certificate. | Self-signed: upload its CA certificate. |

> **Warning:** Orders placed while the environment is **Production** are real. Check the environment before ordering.

## Configure the sandbox

1. Make sure the [credentials and client certificate](/support/help/integrations/vocus-wsm-setup) are saved.
2. Open the **Sandbox** tab, card **Environment**.
3. **Environment**: choose **Sandbox (testing)**.
4. **Sandbox IP address**: use the address Vocus gave you (a documented sandbox address is pre-filled). It must be a public IP.
5. Keep **Connect to this IP in sandbox mode (no hosts-file entry needed)** ticked unless you have set up a hosts-file entry yourself.
6. **Sandbox CA certificate (.pem / .crt)**: upload the CA so the self-signed sandbox certificate can be verified. Tick the remove box to delete a stored one.
7. Select **Save environment**. Switching starts a new Vocus session.

## Run one full journey

The **Sandbox certification checklist** card tracks four steps. Each one is ticked once a successful sandbox call with a TransactionID has been recorded:

| Step | How to do it in {company} |
|---|---|
| Qualify an address or LOC ID (FIBRE Get QUALIFY) | **Admin > Services > Internet > New Order** starts at **Qualify**. |
| Place an order (FIBRE Create) | Continue from qualification to the order form and submit. |
| Wait for polling (TCAS Get RESPONSE) | {company} polls the transaction. You can also use the **Poll** and sandbox poll actions on the service page. |
| View the service (FIBRE Get SERVICE) | Open the service from **Admin > Services > Internet > Manage Internet**. |

Run one complete journey: qualify, order, wait for polling, view the service.

## Export the evidence for Vocus

When the checklist shows a complete journey, use the tiles under **Vocus certification exports** (Administrators only):

- **View sandbox transactions**: the grouped journeys and single calls.
- **Download CSV**: the same data as a file.
- **Printable summary for Vocus**: a page you can save as PDF and send to Vocus.

These are also reachable under **Admin > Audit Logs > Vocus transactions**, where you can choose the environment and a date range.

## Go live

1. Send Vocus the summary and ask for production access for the API methods you use.
2. Once they confirm, open **Sandbox**, set **Environment** to **Production** and **Save environment**.
3. Confirm the production **Access key**, certificate and SOAP endpoint on the **Connection** tab are the production ones Vocus issued (check with Vocus if they differ from sandbox).
4. Start with a small, real, low-risk qualification before ordering.

> **Note:** The steps on Vocus's side (who grants access, how long it takes, which methods they require) are Vocus's process and could not be verified from inside {company}. Check with Vocus.

## Troubleshooting

- **Checklist items stay unticked:** only successful calls with a TransactionID in the **sandbox** environment count. Check the environment on this tab.
- **TLS or certificate errors in sandbox:** upload the sandbox CA and keep the IP pinning option on.
- **Calls to the wrong environment:** the environment is global. Check **Environment** before any order.
