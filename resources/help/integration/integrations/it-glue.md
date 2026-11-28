---
title: IT Glue (organisations and flexible assets)
summary: Connect IT Glue, map clients to organisations and push domains and services as flexible assets.
category: integrations
order: 50
audience: integration
related: [halopsa-sync-and-mappings, sync-scheduler]
---

IT Glue is used as documentation storage: {company} pushes domains and services into IT Glue as flexible assets under the right organisation.

**Where:** **Admin > Settings > IT Glue**, with tabs **Connection**, **Organisations**, **Assets** and **Sync**.

## 1. Create an API key in IT Glue

In IT Glue, generate an API key (this is done in IT Glue's admin settings under API keys) and copy it when it is shown.

> **Note:** The exact IT Glue menu names change between versions, and these steps could not be verified from inside {company}. Check in your provider's portal (IT Glue's own documentation). Use a key that is allowed to read organisations and flexible asset types and to create and update flexible assets.

## 2. Connection tab

1. Open **Admin > Settings > IT Glue > Connection** (card "IT Glue API").
2. **Base URL**: leave blank for the US endpoint (`https://api.itglue.com`). If your IT Glue account is hosted in another region, enter the regional API address that IT Glue gives you. It must be a public https address.
3. **API key**: paste it. It is stored encrypted and never shown again. Type a new key to replace it.
4. Select **Save connection**.

> **Tip:** Save first. The other tabs read their lists using the *saved* credentials.

## 3. Organisations tab

1. Select **Refresh from IT Glue** to update the local mirror of organisations (a background worker must be running).
2. Select **Add**, choose the **DomainDash client** and the **IT Glue organisation** (type to search), and save.

## 4. Assets tab

The **Asset mappings** card has a row for each item type: domains, internet services, hosting services and SSL certificates.

1. Edit a row and tick **Sync this item type to IT Glue**. Only switched-on types are pushed.
2. Choose the **Flexible asset type** (required). Types and fields are read live from IT Glue and cached for 10 minutes.
3. Under **DomainDash value to IT Glue field**, choose the field that receives each value, or **Do not sync this value**. **Suggest from IT Glue** fills guesses by name.
4. Save.

Only **Text**, **Textbox** and **Date** fields can be filled. Required IT Glue fields that aren't mapped will make IT Glue reject the create.

> **Warning:** Changing the mapping re-pushes every record of that type on the next sync. Values already written to a field that is no longer mapped stay in IT Glue; {company} never deletes them.

## 5. Sync tab

- **Sync domains to IT Glue** (under "Configuration items") pushes domains you choose.
- One card per enabled item type with **Sync to IT Glue**. Unchanged records are skipped.
- **Refresh from IT Glue** refreshes the organisation mirror.
- **Schedule** opens the [sync scheduler](/support/help/platform/sync-scheduler).

## Troubleshooting

- **Empty organisation or type list:** save the connection and refresh.
- **Push rejected:** check required flexible asset fields are mapped. The run shows the error. See **Admin > Audit Logs > Integration Outbound** (provider IT Glue).
- **Nothing syncs:** a queue worker must be running; see [Scheduler](/support/help/platform/sync-scheduler).
