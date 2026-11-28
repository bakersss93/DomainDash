---
title: Scheduler, queue worker and sync schedules
summary: Turn on scheduled syncs, make sure the queue worker and cron are running and know what runs automatically.
category: platform
order: 40
audience: integration
related: [halopsa-sync-and-mappings, it-glue, synergy-wholesale]
---

Every Sync or Refresh button, and every scheduled sync, queues a background job. Two server processes are needed or nothing happens:

1. **The scheduler:** `php artisan schedule:run` every minute (cron or a systemd timer).
2. **A queue worker:** `php artisan queue:work`. If your `.env` sets `INTEGRATIONS_QUEUE=integrations`, the worker must listen to it (`--queue=integrations,default`).

Both should run as the web user (for example `www-data`), never as root. Ready-made files are in `deploy/queue/` (a systemd service for the worker, a timer for the scheduler and a cron example). The README section "Queue worker" has the install steps.

If jobs are waiting but no worker is picking them up, a banner says "No queue worker is processing jobs".

## Scheduled syncs

**Where:** **Admin > Settings > Sync scheduler > Schedule** (card "Scheduled syncs"). The card shows the server timezone.

Each job has a tick box, a **Frequency** (**Hourly**, **Daily** or **Weekly**) and a **Run time** (24-hour HH:MM):

| Job | What it does |
|---|---|
| **Sync domains from Synergy** | Updates domains from [Synergy Wholesale](/support/help/integrations/synergy-wholesale). |
| **Sync hosting services** | Updates hosting services. |
| **Sync enabled asset types ... to and from HaloPSA** | Pushes the item types you switched on in the [HaloPSA assets mapping](/support/help/integrations/halopsa-sync-and-mappings). |
| **Sync enabled asset types ... to IT Glue** | Pushes the item types switched on in the [IT Glue assets mapping](/support/help/integrations/it-glue). |

Hourly jobs use the minutes of the run time. Weekly jobs run every Monday. Select **Save schedule**.

## What always runs

These run automatically as long as the scheduler is running (times are server time):

| What | When |
|---|---|
| Synergy domain sync | Nightly 02:30 |
| Halo clients and IT Glue organisation mirrors | Every 6 hours |
| Halo domain asset mirror | Hourly, with a full reconcile at 03:15 |
| Vocus pending transaction polling | Every 10 minutes |
| Database backup | Nightly 03:00 |
| Audit log pruning | Nightly 03:30 |
| Failed queue jobs older than 30 days | Pruned nightly at 03:45 |
| Platform HTTPS expiry check | Daily 07:10 |
| Expiry notifications (domains, SSL) | Hourly |
| Worker heartbeat and stale-run recovery | Every minute and every 5 minutes |

## Check and recover

- **Progress:** the settings pages show each run live (Queued, Running, Succeeded, Partial, Failed) and **Admin > Audit Logs > Jobs & Schedules** keeps the history.
- **Stuck runs:** orphaned runs are failed automatically. An administrator on the server can run `php artisan domaindash:sync-reset --dry-run` to see what would change, then `php artisan domaindash:sync-reset`.
- **Run now:** while the worker banner shows, an administrator with `sync.run` can use **Run now** on a queued domain sync to push a small batch immediately.
- **After every deploy:** restart workers with `php artisan queue:restart`, because workers keep the old code in memory.

## Troubleshooting

- **"Queued" forever:** the worker is not running, or listens on a different queue.
- **Nothing scheduled runs:** cron or the scheduler timer is missing, or running as the wrong user.
- **Times look wrong:** the schedule uses the server's timezone (shown on the card).
