---
title: "DNS changes: propagation and common mistakes"
summary: Why a change is not visible straight away, how to check it, and the mistakes that cause most DNS problems.
category: domains-dns
order: 50
audience: client
permission: dns.manage
related: [managing-dns-records, dns-examples, dns-options]
---

## Propagation: why changes take time

When you save a record, the DNS provider has the new answer immediately. Other networks may still remember the old answer until its **TTL** (time to live) runs out. A record with TTL 3600 can be remembered for up to an hour. Changing **nameservers** (see [DNS options](/support/help/domains-dns/dns-options)) can take longer, from minutes up to a day or two.

> **Tip:** Planning a change? Lower the TTL of the record to 300 a day beforehand. After the change has settled, set it back to 3600.

## Check a record

On Windows with PowerShell:

```powershell
Resolve-DnsName example.com -Type MX
Resolve-DnsName www.example.com -Type CNAME
Resolve-DnsName _dmarc.example.com -Type TXT
Resolve-DnsName example.com -Type A -Server 8.8.8.8
```

The last form asks Google's public DNS (8.8.8.8) instead of your own network. Online DNS lookup websites do the same thing without installing anything.

If you still see the old answer on your own computer, clear its cache:

```powershell
Clear-DnsClientCache
```

## Common mistakes

1. **Typing the whole domain in the Host box.** Use `www`, not `www.example.com`, and `@` for the bare domain.
2. **A CNAME next to other records.** A name with a CNAME can't have anything else. You also can't put a CNAME on `@`. Use an A record there.
3. **Two SPF records.** Only one TXT record starting `v=spf1` is allowed. Merge them into one.
4. **Changing MX records before the new mailboxes exist.** Email may bounce or go missing.
5. **Pasting values with extra spaces or quote marks.** Copy the value exactly. Do not add your own quote marks.
6. **Editing the wrong place.** If the domain's **Current DNS mode** is **Custom nameservers**, the records in this portal aren't used. Change them wherever the nameservers are managed.
7. **Deleting records you don't recognise.** They may belong to email filtering, verification or another service.
8. **Priority on MX records.** Lower numbers win. If you have two mail servers, give the preferred one the lower number, for example 10 and 20.
9. **Forgetting `www`.** A website works at `example.com` and `www.example.com` only if both names have records.

## Still not working?

1. Re-open the DNS page and select **Refresh records** to make sure the record really saved.
2. Compare the value with what the service told you, character by character.
3. Wait for the TTL to pass and check again.
4. [Raise a ticket](/tickets/create) with the domain name, the record you added, and what you see when you check it.
