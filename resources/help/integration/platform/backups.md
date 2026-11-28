---
title: Backups (SFTP with fingerprint and encryption password)
summary: Send encrypted nightly database backups to an SFTP server, pin the server's fingerprint and keep the encryption password safe.
category: platform
order: 20
audience: integration
related: [sync-scheduler, smtp-email]
---

{company} zips the database with AES-256 encryption and uploads it over SFTP. A backup is **refused** unless the server's fingerprint and an encryption password are set.

**Where:** **Admin > Settings > Backups** (card "SFTP backups"). The tile on Settings home shows **Configured** only after a backup has succeeded at least once, and shows the last success time.

## What you need

- An SFTP account on a server you control (a folder it may write to).
- The server's host key fingerprint.
- A strong encryption password you will store somewhere safe.

## Get the SFTP host fingerprint

Run this from a trusted computer (Linux, macOS or Windows with OpenSSH):

```bash
ssh-keyscan -t ed25519 backup.example.com | ssh-keygen -lf -
```

It prints a line containing something like `SHA256:abc123...`. Copy the `SHA256:...` part. Compare it with the value from the server owner if you can.

## Enter the settings

| Field | What to enter |
|---|---|
| **SFTP host** | For example `backup.example.com`. Must be a public hostname (private addresses are rejected unless allowed in server configuration). |
| **Port** | 22 by default. |
| **Username** / **Password** | The SFTP login. The password is stored encrypted. |
| **SFTP host fingerprint** | Required. `SHA256:...` as above. Backups are refused if the server's key does not match. |
| **Backup encryption password** | Required, at least 12 characters. The zip is AES-256 encrypted with it. |
| **Remote path** | The folder on the server, for example `/backups/portal`. No `..` allowed. |
| **Keep backups (count)** | How many files to keep (1 to 365). Older files are removed after each run. |
| **Run time** | Time of day the backup runs, 24-hour `HH:MM` in the server timezone. Blank uses `03:00`. Takes effect from the next scheduler run (no restart needed). |
| **Disk usage alert at (%)** | Raises an alert when the server disk is this full. |

Select **Save backup settings**.

> **Warning:** Store the encryption password and the application's `APP_KEY` somewhere safe and separate from the backups. Without the password a backup cannot be opened. Backups do **not** contain `.env` or `APP_KEY`; without `APP_KEY`, encrypted settings in a restored database cannot be read.

> **Note:** The scheduler runs the backup command once a day at the **Run time** you set (default **03:00 server time**). The server timezone is shown on **Admin > Settings > Sync scheduler**.

## Check it

1. The card shows **Last successful backup** with file name and size, or **No successful backup yet**. A failure shows the last error and time.
2. To run one immediately, an administrator on the server runs `php artisan domaindash:backup-run`.
3. Backup results are written to the audit log (event type `backup.*`).

Local temporary copies are deleted after each run.

## Restore (outline)

1. Download the newest zip from the SFTP server.
2. Open it with the encryption password (a tool that supports AES-256 zip, such as 7-Zip).
3. Restore the database dump into the database and put the original `APP_KEY` back in `.env`.

## Troubleshooting

- **Refused: fingerprint missing or mismatched:** re-run `ssh-keyscan` and re-enter it. A change on the server means a new key. Confirm the change is genuine first.
- **Refused: no encryption password:** set one (12 or more characters).
- **Authentication or path errors:** check the SFTP login and that the remote path exists and is writable.
