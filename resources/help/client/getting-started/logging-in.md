---
title: Logging in
summary: Enter your email, then sign in with your password or with Microsoft single sign-on, and what to do if you forget your password.
category: getting-started
order: 10
audience: client
related: [setting-up-mfa, update-your-account]
---

Your {company} portal account is created for you by your provider, so there is no "Sign up" page. If you don't have an account yet, ask your provider's support team to create one using your work email address.

## How login works

The login page asks for your **Email** first, then shows the right way for you to sign in. Type your work email and select **Continue**. If your browser allows it, the page switches on its own a moment after you finish typing the address, so you may not need to select **Continue**.

If you typed the wrong address, select **Use a different email** to start again.

## Log in with your email and password

This is what you see unless your account uses single sign-on.

1. Go to {app_url} and enter your **Email**.
2. Select **Continue** (or wait for the **Password** box to appear).
3. Enter your **Password**. Select **Show** next to the password box if you want to check what you typed.
4. Tick **Keep me logged in** only on a computer that nobody else uses.
5. Select **Log in**.
6. If multi-factor authentication (MFA) is switched on for you, you are asked for a **Code**. Open your authenticator app, type the current 6-digit code, then select **Log in** again. If you have lost your phone, select **Use a recovery code**. See [Setting up and using MFA](/support/help/getting-started/setting-up-mfa).

> **Tip:** Too many wrong attempts in a short time temporarily blocks further tries. Wait a minute and try again.

## Log in with single sign-on (Microsoft)

If your provider has turned on Microsoft sign-in **for your account**, the page changes to **Single sign-on** as soon as it recognises your email. There is no password box.

1. Enter your work **Email** (the same address you use for Microsoft 365).
2. Select **Log in with Microsoft** (or **Log in**).
3. Sign in on the Microsoft page that opens. You come back to the portal signed in.

If your provider also lets you use a password, a **Use password instead** link appears under the button.

- Select it to show the **Password** box and **Log in**.
- Under the password form there is an **Or** divider and a **Log In with Single Sign-On** button, so you can switch back. Select **Back** to return to the single sign-on view.

Things to know:

- Your account must already exist in the portal, and your provider must have switched Microsoft sign-in on for you. If they have not, you simply see the password form, even if your organisation uses Microsoft 365. Ask your provider to enable it.
- The first time you use it, the portal links your Microsoft account to your portal account. After that, only that Microsoft account can be used for Microsoft sign-in.
- Some organisations switch passwords off and allow **Microsoft only**. If that applies to you, your password is rejected with the same message as a wrong password, and there is no **Use password instead** link.
- Your Microsoft administrator may need to approve the sign-in the first time ("Need admin approval"). Ask your provider for the approval link to send them.

## Forgot your password?

1. On the password step of the login page select **Forgot password?**
2. Enter your **Email** and select **Email Password Reset Link**.
3. Open the email, select the link and choose a new password.

> **Note:** In production the new password must be at least 12 characters, include upper and lower case letters and a number, and must not be a password that has appeared in a known data breach.

If the email does not arrive within a few minutes, check your spam folder and then [raise a ticket](/tickets/create).

## Signing out

Open the menu with your name at the top right and select **Logout**.
