---
title: Running a connection test
summary: Check the recent sign-in attempts of your internet connection to help diagnose drop-outs.
category: services
order: 30
audience: client
permission: internet.diagnostics
related: [internet-services, raising-a-ticket]
---

The connection test looks at the recent sign-in attempts your connection made to the network. It is read-only and does not interrupt your service.

## Before you start

The test is only available when **both** of these are true:

- your provider has turned on internet diagnostics for your organisation, and
- your account has the `internet.diagnostics` permission.

If you don't see the **Connection test** card, one of these is off. Ask your provider if you would like it.

## Run the test

1. In the left menu select **Internet**.
2. Select **View** next to the service.
3. In the **Connection test** card, select **Run connection test**.
4. Wait for "Connection test completed". Results appear below the button.

## Read the result

Each run is listed with how long ago it ran, then one of:

- "no recent sign-in attempts were recorded" means the connection has not tried to sign in recently.
- "all N recent sign-in attempts succeeded" means the connection is signing in normally.
- "X of N recent sign-in attempts succeeded, Y failed" means some attempts failed. If this keeps happening, contact support.

A table shows each **Time** and **Result** (**Connected** or **Failed**).

> **Tip:** If your internet keeps dropping, run the test straight afterwards and include the result in your ticket. It helps us find the cause faster.

> **Note:** You can run only a handful of tests every few minutes. If you are asked to wait, try again shortly. If the test is not available for a service, you will see a message saying so. For example, some service types cannot be tested.

## What to do next

Before you raise a ticket, try restarting your modem and router. If the problem continues, [raise a ticket](/tickets/create) with the Service ID, what you see, and when it started.
