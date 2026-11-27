#!/bin/bash
# DomainDash Platform HTTPS - installer (idempotent). Run as root on the server:
#   sudo bash deploy/https/install.sh [--app-dir /var/www/DomainDash] [--php /usr/bin/php]
#                                     [--web-user www-data] [--allow-writable-code] [--no-start]
#   sudo bash deploy/https/install.sh --uninstall
#
# Installs the root helper wrappers, systemd units/timers and the nginx snippet placeholder.
# It never edits your nginx server blocks and never issues a certificate.
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SRC_DIR/../.." && pwd)"
PHP_BIN="$(command -v php || true)"
WEB_USER="www-data"
ALLOW_WRITABLE=0
START=1
UNINSTALL=0

LIB_DIR=/usr/local/lib/domaindash
SSL_DIR=/etc/ssl/domaindash
SNIPPETS=/etc/nginx/snippets
SNIPPET="$SNIPPETS/domaindash-ssl.conf"
UNIT_DIR=/etc/systemd/system

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --app-dir) APP_DIR="$(cd "${2:?}" && pwd)"; shift 2 ;;
    --php) PHP_BIN="${2:?}"; shift 2 ;;
    --web-user) WEB_USER="${2:?}"; shift 2 ;;
    --allow-writable-code) ALLOW_WRITABLE=1; shift ;;
    --no-start) START=0; shift ;;
    --uninstall) UNINSTALL=1; shift ;;
    -h|--help) sed -n '2,10p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "run as root (sudo)."

if [ "$UNINSTALL" -eq 1 ]; then
  info "Stopping and removing timers/units"
  systemctl disable --now domaindash-certctl.timer domaindash-certbot-renew.timer 2>/dev/null || true
  rm -f "$UNIT_DIR"/domaindash-certctl.{service,timer} "$UNIT_DIR"/domaindash-certbot-renew.{service,timer}
  systemctl daemon-reload
  rm -rf "$LIB_DIR"
  cat <<MSG
Removed the helper, wrappers and units. NOT removed (remove by hand if you really want to):
  $SNIPPET and the 'include' lines in your nginx server blocks (nginx will fail to load if the include
    remains while the file is gone - remove the include first, then 'nginx -t && systemctl reload nginx')
  $SSL_DIR            uploaded certificates / keys
  /etc/letsencrypt    certbot lineages ('certbot delete --cert-name <host>' to revoke renewal)
MSG
  exit 0
fi

# ---- preconditions ---------------------------------------------------------
[ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ] || die "php not found; pass --php /path/to/php"
[ -f "$APP_DIR/artisan" ] || die "$APP_DIR does not look like DomainDash (no artisan)"
[ -x /usr/bin/certbot ] || die "/usr/bin/certbot missing. pip install: ln -s /opt/certbot/bin/certbot /usr/bin/certbot"
[ -x /usr/sbin/nginx ] || die "/usr/sbin/nginx not found"
[ -x /usr/bin/systemctl ] && [ -x /usr/bin/flock ] || die "systemctl and flock are required"
id "$WEB_USER" >/dev/null 2>&1 || die "web user '$WEB_USER' does not exist (use --web-user)"
case "$APP_DIR$PHP_BIN" in *[!A-Za-z0-9._/@+-]*) die "app dir / php path contain unsupported characters" ;; esac

# The helper runs PHP code from the app directory AS ROOT. If the web user can modify that code, a
# web compromise becomes root. Refuse unless the code is read-only for the web user.
info "Checking that $WEB_USER cannot modify code that root will execute"
writable=""
for p in artisan app bootstrap config routes vendor database .env composer.json; do
  [ -e "$APP_DIR/$p" ] || continue
  hit="$(runuser -u "$WEB_USER" -- find "$APP_DIR/$p" -writable -print -quit 2>/dev/null || true)"
  [ -z "$hit" ] || writable="$writable"$'\n'"  $hit"
done
if [ -n "$writable" ]; then
  echo "$WEB_USER can write to code the root helper executes, e.g.:$writable" >&2
  if [ "$ALLOW_WRITABLE" -ne 1 ]; then
    cat >&2 <<MSG

Fix (recommended): keep code root-owned, only storage/ writable by the web user:
  chown -R root:root $APP_DIR
  chown -R $WEB_USER:$WEB_USER $APP_DIR/storage
  # bootstrap/cache must be pre-built (the web user cannot write it):
  (cd $APP_DIR && $PHP_BIN artisan package:discover && $PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache)
  chmod -R go-w $APP_DIR/bootstrap/cache; chmod 640 $APP_DIR/.env; chgrp $WEB_USER $APP_DIR/.env
Re-run after every deploy that changes code. Override (NOT recommended): --allow-writable-code
MSG
    exit 2
  fi
  echo "WARNING: continuing because of --allow-writable-code. A web-user compromise can become root." >&2
fi

# ---- install ---------------------------------------------------------------
info "Installing wrappers into $LIB_DIR"
install -d -m 0755 -o root -g root "$LIB_DIR"
for f in certctl-run acme-dns-auth acme-dns-cleanup certbot-deploy-hook; do
  sed -e "s|@APP_DIR@|$APP_DIR|g" -e "s|@PHP_BIN@|$PHP_BIN|g" "$SRC_DIR/bin/$f" > "$LIB_DIR/$f.new"
  chown root:root "$LIB_DIR/$f.new"; chmod 0755 "$LIB_DIR/$f.new"
  mv -f "$LIB_DIR/$f.new" "$LIB_DIR/$f"
done

info "Creating $SSL_DIR and the nginx snippet placeholder"
install -d -m 0755 -o root -g root "$SSL_DIR" "$SNIPPETS"
if [ ! -e "$SNIPPET" ]; then
  printf '# Managed by DomainDash (domaindash:certctl). Empty until a certificate is installed.\n' > "$SNIPPET"
  chmod 0644 "$SNIPPET"
fi

info "Installing systemd units"
for u in domaindash-certctl.service domaindash-certctl.timer domaindash-certbot-renew.service domaindash-certbot-renew.timer; do
  sed -e "s|@APP_DIR@|$APP_DIR|g" "$SRC_DIR/systemd/$u" > "$UNIT_DIR/$u"
  chmod 0644 "$UNIT_DIR/$u"
done
systemctl daemon-reload

if [ "$START" -eq 1 ]; then
  systemctl enable --now domaindash-certctl.timer domaindash-certbot-renew.timer
  # Existing certbot installs from pip have no timer; make sure we do not double-schedule the apt/snap one.
  systemctl list-unit-files certbot.timer >/dev/null 2>&1 && echo "NOTE: a distro certbot.timer also exists; both are safe (certbot renew is idempotent)."
fi

cat <<MSG

Installed. Next steps:
  1. Migrate the app (as the deploy user):   cd $APP_DIR && php artisan migrate --force
  2. Sign in as an Administrator -> Settings -> Platform HTTPS. The "helper not running" warning should
     clear within a minute (check: systemctl list-timers 'domaindash-*'; journalctl -u domaindash-certctl).
  3. Request a Let's Encrypt certificate (try the staging box first) or upload one.
  4. Add the 443 server block from $SRC_DIR/nginx-domaindash.conf.example (or just
     'include $SNIPPET;' in your existing 443 block), then: nginx -t && systemctl reload nginx
  5. Open https://<host>/, then enable "Force HTTPS" in the UI.
Uninstall: sudo bash $SRC_DIR/install.sh --uninstall
MSG
