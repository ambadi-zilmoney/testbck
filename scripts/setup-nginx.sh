#!/usr/bin/env bash
# One-time server setup: installs nginx + certbot, gets a Let's Encrypt certificate
# for the API domain, and enables the nginx site from nginx/api.conf.
#
# Usage (Ubuntu/Debian, as root, from the project root):
#   sudo ./scripts/setup-nginx.sh api.example.com example.com admin@example.com
#
# Before running: DNS A record for the API domain must point to this server,
# and ports 80 + 443 must be open.
set -euo pipefail

API_DOMAIN="${1:?Usage: $0 <api-domain> <frontend-domain> <email>}"
FRONTEND_DOMAIN="${2:?Usage: $0 <api-domain> <frontend-domain> <email>}"
EMAIL="${3:?Usage: $0 <api-domain> <frontend-domain> <email>}"

cd "$(dirname "$0")/.."
SITE="/etc/nginx/sites-available/${API_DOMAIN}.conf"

echo "==> Installing nginx and certbot"
apt-get update -qq
apt-get install -y -qq nginx certbot

mkdir -p /var/www/certbot
rm -f /etc/nginx/sites-enabled/default

if [[ ! -f "/etc/letsencrypt/live/${API_DOMAIN}/fullchain.pem" ]]; then
  echo "==> Temporary HTTP-only site so Let's Encrypt can verify the domain"
  cat > "$SITE" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${API_DOMAIN};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
}
EOF
  ln -sf "$SITE" "/etc/nginx/sites-enabled/${API_DOMAIN}.conf"
  nginx -t && systemctl reload nginx

  echo "==> Requesting certificate for ${API_DOMAIN}"
  certbot certonly --webroot -w /var/www/certbot -d "$API_DOMAIN" \
    --email "$EMAIL" --agree-tos --no-eff-email --non-interactive
fi

echo "==> Installing the API site"
sed -e "s/__API_DOMAIN__/${API_DOMAIN}/g" -e "s/__FRONTEND_DOMAIN__/${FRONTEND_DOMAIN}/g" \
  nginx/api.conf > "$SITE"
ln -sf "$SITE" "/etc/nginx/sites-enabled/${API_DOMAIN}.conf"
nginx -t && systemctl reload nginx

echo "==> Reload nginx automatically after each certificate renewal"
install -d /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'EOF'
#!/bin/sh
systemctl reload nginx
EOF
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

# The certbot package ships a systemd timer that runs `certbot renew` twice a day.
systemctl enable --now certbot.timer 2>/dev/null || true
certbot renew --dry-run

echo "==> Done: https://${API_DOMAIN}/api/health"
