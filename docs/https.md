# Enabling HTTPS with Let's Encrypt

These steps provision HTTPS for DomainDash using Nginx and Certbot. Adjust paths as needed for your environment.

## Prerequisites

- Nginx installed
- PHP-FPM installed and configured for the DomainDash codebase
- Certbot installed (`apt install certbot python3-certbot-nginx` on Debian/Ubuntu)
- The application files available at `/var/www/DomainDash` (or another path of your choice)

Ensure your domain’s DNS `A`/`AAAA` records point to the server before requesting a certificate.

## 1) Configure Nginx

1. Copy the sample config:
   ```bash
   sudo cp deploy/nginx/domain-dash.conf /etc/nginx/sites-available/domain-dash.conf
   ```
2. Edit `/etc/nginx/sites-available/domain-dash.conf`:
   - Set `server_name` to your domain.
   - Update `root` if the project lives elsewhere.
   - Point `fastcgi_pass` at your PHP-FPM socket or host/port.
3. Enable the site and test the config:
   ```bash
   sudo ln -s /etc/nginx/sites-available/domain-dash.conf /etc/nginx/sites-enabled/
   sudo nginx -t
   sudo systemctl reload nginx
   ```

## 2) Request the certificate

Run the helper script with your domain, contact email, and the webroot pointing at `public/`:

```bash
sudo ./scripts/ssl/issue_certificate.sh example.com admin@example.com /var/www/DomainDash/public
```

For a safe test against Let’s Encrypt staging:

```bash
sudo ./scripts/ssl/issue_certificate.sh example.com admin@example.com /var/www/DomainDash/public --staging
```

After a successful issuance, reload Nginx to start serving HTTPS.

## 3) Automatic renewal

Add a daily cron entry (as root) to renew and reload Nginx automatically:

```bash
0 3 * * * root /var/www/DomainDash/scripts/ssl/renew_certificates.sh >> /var/log/letsencrypt/renew.log 2>&1
```

Environment overrides:
- `RELOAD_CMD` — command to reload your web server (default: `systemctl reload nginx`)
- `DRY_RUN` — set to any value to run `certbot renew --dry-run`

## 4) Laravel configuration

Set `APP_URL=https://your-domain.example` in your `.env` file so generated URLs use HTTPS. If you serve the app behind a load balancer or proxy that terminates TLS, also ensure it forwards the `X-Forwarded-Proto` header so Laravel detects secure requests correctly.
