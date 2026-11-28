---
title: XSS fixture article
summary: Contains hostile markup that must be neutralised.
category: general
order: 30
audience: client
---

Before <script>alert('xss-one')</script> after.

<img src=x onerror="alert('xss-two')">

<iframe src="https://evil.example.com"></iframe>

[click me](javascript:alert('xss-three'))

[data link](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)

[safe link](https://example.com/ok)
