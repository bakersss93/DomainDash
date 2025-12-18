#!/usr/bin/env bash
set -euo pipefail

# Renew Let's Encrypt certificates and reload Nginx when new certificates are deployed.
# Optional env vars:
#   RELOAD_CMD: command to run after renewal (default: "systemctl reload nginx")
#   DRY_RUN: set to any value to perform a dry run

RELOAD_CMD="${RELOAD_CMD:-systemctl reload nginx}"
DRY_RUN_FLAG=()

if [[ -n "${DRY_RUN:-}" ]]; then
  DRY_RUN_FLAG+=(--dry-run)
fi

certbot renew \
  --quiet \
  --deploy-hook "$RELOAD_CMD" \
  "${DRY_RUN_FLAG[@]}"

echo "Renewal check complete. Deploy hook: $RELOAD_CMD"
