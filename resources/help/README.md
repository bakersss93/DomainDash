# Support > Help articles

Each article is a Markdown file at `resources/help/{audience}/{category}/{slug}.md`.

- `audience`: `client` (visible to any signed-in user) or `integration` (staff only, gated by the `help.integrations.view` permission).
- `category` and `slug`: lower-case letters, digits and hyphens only. Anything else is ignored. The category's display name and order live in `config/help.php`.

## Front matter

```
---
title: Managing DNS records
summary: One sentence shown on the index and in search results.
category: domains-dns        # must match the folder
order: 20                    # sort order inside the category
audience: client             # must match the folder
permission: dns.manage       # optional: hide from users without this permission (staff see all client articles)
related: [dns-examples]      # optional: slugs shown under "Related articles"
---
```

## Writing rules

- Plain English, short steps, numbered lists. Callouts are blockquotes that start with `**Tip:**`, `**Note:**`, `**Warning:**` or `**Important:**`.
- Raw HTML is escaped and `javascript:` links are dropped when rendering, so don't use HTML.
- Placeholders: `{company}` (the **Company name** set in Admin > Settings > Branding, which the login page heading "Log in to ..." also uses; falls back to the application name) and `{app_url}`. Use `example.com` and `contoso.com` in examples. Never put an owner's name or domain in an article.
- Internal links: `/support/help/{category}/{slug}` or `/tickets/create`. A test checks every link points at a real article.
- Document only what exists in the product, with the menu path and field names as they appear on screen. When a step happens in another vendor's portal and could not be verified, say so ("check in your provider's portal").
- Integration articles are for the people who configure the product. Client articles must not mention internal settings.

The index is cached and rebuilds itself when a file's modified time or size changes.
