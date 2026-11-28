---
title: "Vocus WSM: credentials and client certificate"
summary: Enter the Vocus access key, upload the yearly client certificate and confirm the connection is ready.
category: integrations
order: 70
audience: integration
related: [vocus-sandbox-and-certification, vocus-plans-and-ordering]
---

{company} orders and manages NBN services through the Vocus Wholesale Service Management (WSM) SOAP API. The API needs an **access key** and a **client certificate**, both issued by Vocus during onboarding.

**Where:** **Admin > Settings > Vocus Wholesale**, with tabs **Connection**, **Ordering**, **Plans**, **Service records** and **Sandbox**.

## What Vocus gives you

- An **Access key** (and sometimes an **Alias key**).
- A client certificate file (**.p12 / .pfx**, or a Java **.keystore / .jks**) and its password.
- Sandbox access for testing, granted by Vocus. See [sandbox and certification](/support/help/integrations/vocus-sandbox-and-certification).

> **Note:** How and when Vocus issues or renews these is handled by Vocus during onboarding. Check with your Vocus account manager or their portal. This could not be verified from inside {company}.

## Enter the credentials

1. Open **Admin > Settings > Vocus Wholesale > Connection** (card "Vocus WSM credentials").
2. If a yellow **Vocus is not ready** notice shows, it names what is missing (access key or certificate).
3. **Access key**: paste it. Leave `********` to keep the stored key.
4. **Alias key (optional)**: only if Vocus gave you one.
5. **SOAP endpoint URL**: leave the default (`https://wsm.webservice.m2.com.au/WholesaleServiceManagement`). It applies to production only; sandbox endpoints follow the environment on the Sandbox tab. It must be https.
6. **Certificate login URL**: leave the default (`https://wsm.webservice.m2.com.au:9443/login/`) unless Vocus tells you otherwise.
7. **Client certificate**: choose the file (`.p12`, `.pfx`, `.keystore` or `.jks`, up to 512 KB).
8. **Certificate password**: type the password. It is stored encrypted and never shown.
9. Select **Save connection**.

On upload the certificate and password are checked. If the password is wrong or the file is not valid you get an error and nothing is changed. "Certificate on file" appears once a certificate has been stored.

> **Warning:** Java JKS files must be converted to PKCS#12 first. With the Java `keytool` this is typically `keytool -importkeystore -srckeystore client.jks -destkeystore client.p12 -deststoretype PKCS12`. Upload the resulting `.p12`.

## Renew the certificate (yearly)

Vocus issues a new certificate every year.

1. Upload the new file in the same field and enter its password, then **Save connection**. It replaces the old one.
2. Put a reminder in your calendar a month before expiry. When the certificate expires, Vocus calls fail until a new one is uploaded.

## Environment: production or sandbox

The environment is chosen on the **Sandbox** tab. Production is the default. Switching starts a new Vocus session. See [sandbox and certification](/support/help/integrations/vocus-sandbox-and-certification).

## Check it

1. Settings home: the Vocus tile shows **Configured** once an access key is stored.
2. The "Vocus is not ready" notice is gone from the Connection tab.
3. Try a qualification: **Admin > Services > Internet > New Order**. See [plans and ordering](/support/help/integrations/vocus-plans-and-ordering).
4. Check calls in **Admin > Audit Logs > Vocus transactions** and **Integration Outbound**.

## Troubleshooting

- **"The Vocus settings could not be decrypted (was APP_KEY changed?)":** the encryption key changed. Re-enter the access key, alias key and certificate password.
- **"Access Key is not configured" or "certificate has not been uploaded":** complete the steps above.
- **Calls fail with certificate errors:** the certificate may have expired or the password changed. Upload the renewed file.
