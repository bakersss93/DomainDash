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
6. The portal now shows **Save your recovery codes**. Copy them or select **Download**, keep them somewhere safe such as a password manager, tick **I have saved these recovery codes** and select **Done**. See "Recovery codes" below.

From then on, after you enter your password you are asked for a **Code**. Type the current 6-digit code from the app and select **Log in**.

> **Tip:** Codes change every 30 seconds. If a code is rejected, wait for the next one and try again. Make sure your phone's clock is set to automatic.

## If your account requires MFA

When MFA is required, nothing else in the portal is available until setup is finished. The dashboard only shows a setup page ("Set up multi-factor authentication") with a **Start setup** button.

## Move to a new phone or start again (re-enrol)

1. Open the menu with your name (top right) and select **Account Settings**.
2. Select **Re-enroll MFA**.
3. Type your current MFA code when asked, then follow the setup steps above with your new phone.
4. You get a **new set of recovery codes** at the end. The old codes stop working.

## Recovery codes

Recovery codes are a backup way in if you lose your phone. You get eight codes the moment MFA setup finishes. Each code works **once**.

> **Warning:** The codes are shown only once, on the **Save your recovery codes** screen. Store them safely (a password manager or a printed copy in a safe place) and never share them. Anyone with a recovery code and your password can sign in as you.

**Use a recovery code to sign in**

1. Enter your email and password as usual until the **Code** step appears.
2. Select **Use a recovery code**.
3. Type one of your unused codes in the **Recovery Code** box and select **Log in**.
4. That code is now used up. Once you are in, generate a fresh set (below) if you are running low.

**Make new recovery codes**

1. Open the menu with your name (top right) and select **Account Settings**.
2. Select **Regenerate recovery codes**, type the current 6-digit code from your authenticator app and select the button again.
3. Save the new codes from the **Save your recovery codes** screen. **All of your old codes stop working immediately.**

Generating or using recovery codes is recorded in the audit log.

## Lost your phone?

If you saved your recovery codes, use one at the sign-in **Code** step (see "Recovery codes" above), then set up MFA again with your new phone using **Re-enroll MFA**.

If you do not have your recovery codes, you cannot sign in without a code and your provider needs to reset your MFA.

1. Contact your provider's support team.
2. They confirm who you are and reset your MFA.
3. Sign in with your password and set MFA up again.

> **Warning:** Never share a code with anyone, including people who say they are from support. Your provider will never ask for one.

## Signed in with Microsoft?

If your provider trusts Microsoft's own multi-factor check, you may not be asked for a second code in the portal. Otherwise the portal asks for its own code as normal.
