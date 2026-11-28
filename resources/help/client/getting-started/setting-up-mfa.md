---
title: Setting up and using multi-factor authentication (MFA)
summary: Add an authenticator app to your account so a stolen password alone is not enough to get in.
category: getting-started
order: 20
audience: client
related: [logging-in, update-your-account]
---

Multi-factor authentication (MFA) asks for a short code from your phone as well as your password. Even if someone learns your password they cannot sign in without your phone.

## What you need

- A phone or tablet with an authenticator app, such as Microsoft Authenticator, Google Authenticator or any app that supports 6-digit time-based codes (TOTP).

## Set up MFA

1. Log in. If MFA is required for your account, the portal shows **Secure your account with MFA** straight away. If it is optional, you are offered it from time to time and can choose **Remind me later**.
2. Select **Next**.
3. Open your authenticator app and scan the QR code. If scanning does not work, choose the app's manual entry option and type in the **Setup Key** shown below the QR code.
4. Select **Next**, then type the 6-digit **Authenticator code** your app now shows.
5. Select **Verify and save MFA**.

From then on, after you enter your password you are asked for a **Code**. Type the current 6-digit code from the app and select **Log in**.

> **Tip:** Codes change every 30 seconds. If a code is rejected, wait for the next one and try again. Make sure your phone's clock is set to automatic.

## If your account requires MFA

When MFA is required, nothing else in the portal is available until setup is finished. The dashboard only shows a setup page ("Set up multi-factor authentication") with a **Start setup** button.

## Move to a new phone or start again (re-enrol)

1. Open the menu with your name (top right) and select **Account Settings**.
2. Select **Re-enroll MFA**.
3. Type your current MFA code when asked, then follow the setup steps above with your new phone.

## Lost your phone?

You cannot sign in without a code. The sign-in page has a **Use a recovery code** link, but the portal does not display recovery codes to you, so you will normally need your provider to reset your MFA.

1. Contact your provider's support team.
2. They confirm who you are and reset your MFA.
3. Sign in with your password and set MFA up again.

> **Warning:** Never share a code with anyone, including people who say they are from support. Your provider will never ask for one.

## Signed in with Microsoft?

If your provider trusts Microsoft's own multi-factor check, you may not be asked for a second code in the portal. Otherwise the portal asks for its own code as normal.
