#!/usr/bin/env bash
set -euo pipefail

# Issue an initial Let's Encrypt certificate using the webroot challenge.
# Usage: ./scripts/ssl/issue_certificate.sh <domain> <email> <webroot> [--staging]
#
# Example:
#   ./scripts/ssl/issue_certificate.sh example.com admin@example.com /var/www/DomainDash/public

if [[ $# -lt 3 ]]; then
  echo "Usage: $0 <domain> <email> <webroot> [--staging]" >&2
  exit 1
fi

DOMAIN=$1
EMAIL=$2
WEBROOT=$3
shift 3

if [[ ! -d "$WEBROOT" ]]; then
  echo "Webroot directory '$WEBROOT' does not exist." >&2
  exit 1
fi

EXTRA_ARGS=()
for arg in "$@"; do
  case "$arg" in
    --staging)
      EXTRA_ARGS+=(--staging)
      ;;
    *)
      echo "Unknown argument: $arg" >&2
      exit 1
      ;;
  esac
done

certbot certonly \
  --non-interactive \
  --agree-tos \
  --email "$EMAIL" \
  --webroot -w "$WEBROOT" \
  -d "$DOMAIN" \
  "${EXTRA_ARGS[@]}"

echo "Certificate request for $DOMAIN complete. Reload Nginx to begin serving HTTPS."
