# Telegram-only HTTPS access

The bot uses `getUpdates` long polling and needs no inbound web listener. A browser opening a `t.me` link or Telegram's in-app browser connects from the **user's IP**, not Telegram's server IP. This policy blocks those visitors, including administrators. Local CLI administration still works. There is no webhook endpoint in this application: do not call `setWebhook` while using this deployment.

For an optional website, these configurations deny all application HTTP access except Telegram's published source subnets:

- `149.154.160.0/20`
- `91.108.4.0/22`

Verify the ranges against the [official Telegram webhook documentation](https://core.telegram.org/bots/webhooks) before installing and during updates; Telegram can change them. IP filtering does not authenticate a particular bot/user and does not replace activation keys.

## Install on the native dedicated server

First complete the [Debian 13 guide](deploy-debian13.md). Point the site's DNS directly at the server, without a CDN or HTTP reverse proxy. Provision a valid certificate for that hostname using your certificate authority's **DNS-01** flow; install its full chain and private key at `/etc/ssl/coin-bot/fullchain.pem` and `/etc/ssl/coin-bot/privkey.pem`. Keep the private key root-owned with mode `0600`, configure automatic renewal, and reload Nginx after renewal. Public HTTP/ACME exceptions are deliberately absent.

On a fresh dedicated server, restrict network ingress **before** installing a web server. Replace `22` with the actual SSH port, preserve all required administrator access, and keep `IPV6=yes` in `/etc/default/ufw`. Existing hosts require review of their firewall rules: broad existing web-port allowances must be removed; adding narrow allowances does not override them.

```bash
sudo apt-get install -y ufw
SSH_PORT=22
sudo ufw allow "$SSH_PORT/tcp"
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow proto tcp from 149.154.160.0/20 to any port 443
sudo ufw allow proto tcp from 91.108.4.0/22 to any port 443
sudo ufw enable
sudo ufw status numbered
```

Do not allow `80/tcp`, global `443/tcp`, or the `Nginx Full` profile. Keep outbound HTTPS, DNS, and update traffic available. There is no IPv6 web allowance, so IPv6 web ingress stays blocked. If a cloud firewall is present, apply the same web-source restrictions there while preserving SSH.

With the certificate installed and the firewall enabled:

```bash
cd /home/www/coinbot
sudo apt-get install -y nginx php8.5-fpm
sudo install -m 0644 deploy/nginx/telegram-only.conf /etc/nginx/snippets/coin-bot-telegram-only.conf
sudo install -m 0644 deploy/nginx/coin-bot.conf /etc/nginx/sites-available/coin-bot
sudo install -m 0644 deploy/php-fpm/coin-bot.conf /etc/php/8.5/fpm/pool.d/coin-bot.conf
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sfn /etc/nginx/sites-available/coin-bot /etc/nginx/sites-enabled/coin-bot
sudo php-fpm8.5 --test
sudo nginx -t
sudo systemctl enable --now php8.5-fpm nginx
sudo systemctl restart php8.5-fpm
sudo systemctl reload nginx
```

Ensure this is the only enabled website on the dedicated host and no other web listener exposes the application. The dedicated FPM pool runs as `coin-bot` so it can read the private `.env`; the public web root is strictly `/home/www/coinbot/public`. The catch-all HTTPS server applies the allowlist to static files and PHP routes. Port 80 has no configured listener after disabling the default site.

The `coin-bot` and `www-data` users must be able to traverse `/home` and `/home/www`. Preserve existing parent permissions; do not expose other home directories to solve an application access error.

Do not enable `real_ip_header` or trust arbitrary `X-Forwarded-For`/`X-Real-IP` headers. Nginx must see the actual network source IP. Review future locations carefully: child `allow`/`deny` directives replace inherited ones, and rewrite-phase `return` responses can run before access checks. See the [Nginx access module](https://nginx.org/en/docs/http/ngx_http_access_module.html).

## Verify

Run `cb ops:health` locally for application readiness. From an unrelated external IP, HTTPS must be blocked by the firewall; bypassing or missing the firewall must still yield Nginx `403` for application requests. Spoofing `X-Forwarded-For` with a Telegram IP must not grant access. Verify IPv4 and IPv6 independently where available, including `/health`, `/up`, `/index.php`, static files, and unknown paths. Test the Nginx policy separately on an isolated local listener before deployment if the network firewall masks its result.

These files are deployment configuration, not evidence that any live host's firewall/Nginx has been changed. Genuine Telegram-origin webhook delivery cannot be an acceptance check for the current polling-only application.
