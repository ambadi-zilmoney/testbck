#!/usr/bin/env bash
# One-time server setup for hosting BOTH sites on this machine:
#   https://<frontend-domain>  -> static React files in /var/www/<frontend-domain>/current
#   https://<api-domain>       -> PHP API in Docker (127.0.0.1:8081 / 8082)
# Installs nginx + certbot, gets one Let's Encrypt certificate per domain, enables both sites,
# and sets up automatic renewal.
#
# Usage (Ubuntu, from the repo root):
#   sudo ./scripts/setup-nginx.sh api.example.com example.com admin@example.com
#
# Before running: DNS A records for <api-domain>, <frontend-domain> and www.<frontend-domain>
# must all point to this server, and ports 80 + 443 must be open.
set -euo pipefail

API_DOMAIN="${1:?Usage: $0 <api-domain> <frontend-domain> <email>}"
FRONTEND_DOMAIN="${2:?Usage: $0 <api-domain> <frontend-domain> <email>}"
EMAIL="${3:?Usage: $0 <api-domain> <frontend-domain> <email>}"
DEPLOY_USER="${SUDO_USER:-ubuntu}"   # the SSH user that uploads frontend builds

cd "$(dirname "$0")/.."
API_SITE="/etc/nginx/sites-available/${API_DOMAIN}.conf"
FRONT_SITE="/etc/nginx/sites-available/${FRONTEND_DOMAIN}.conf"
WEB_ROOT="/var/www/${FRONTEND_DOMAIN}"

echo "==> Installing nginx and certbot"
apt-get update -qq
apt-get install -y -qq nginx certbot
mkdir -p /var/www/certbot
rm -f /etc/nginx/sites-enabled/default

echo "==> Preparing ${WEB_ROOT} (owned by ${DEPLOY_USER} so deploys can upload without sudo)"
mkdir -p "${WEB_ROOT}/releases"
if [[ ! -e "${WEB_ROOT}/current" ]]; then
  mkdir -p "${WEB_ROOT}/releases/placeholder"
  echo "<!doctype html><title>${FRONTEND_DOMAIN}</title><p>Deploy pending.</p>" > "${WEB_ROOT}/releases/placeholder/index.html"
  ln -s "${WEB_ROOT}/releases/placeholder" "${WEB_ROOT}/current"
fi
chown -R "${DEPLOY_USER}:www-data" "${WEB_ROOT}"
chmod -R u=rwX,g=rX,o=rX "${WEB_ROOT}"

# Temporary HTTP-only sites so Let's Encrypt can verify each domain
echo "==> Temporary HTTP sites for certificate validation"
for domain in "$API_DOMAIN" "$FRONTEND_DOMAIN"; do
  names="$domain"; [[ "$domain" == "$FRONTEND_DOMAIN" ]] && names="$domain www.$domain"
  cat > "/etc/nginx/sites-available/${domain}.conf" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${names};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
}
EOF
  ln -sf "/etc/nginx/sites-available/${domain}.conf" "/etc/nginx/sites-enabled/${domain}.conf"
done
nginx -t && systemctl reload nginx

if [[ ! -f "/etc/letsencrypt/live/${API_DOMAIN}/fullchain.pem" ]]; then
  echo "==> Requesting certificate for ${API_DOMAIN}"
  certbot certonly --webroot -w /var/www/certbot -d "$API_DOMAIN" \
    --email "$EMAIL" --agree-tos --no-eff-email --non-interactive
fi
if [[ ! -f "/etc/letsencrypt/live/${FRONTEND_DOMAIN}/fullchain.pem" ]]; then
  echo "==> Requesting certificate for ${FRONTEND_DOMAIN} + www.${FRONTEND_DOMAIN}"
  certbot certonly --webroot -w /var/www/certbot -d "$FRONTEND_DOMAIN" -d "www.${FRONTEND_DOMAIN}" \
    --email "$EMAIL" --agree-tos --no-eff-email --non-interactive
fi

echo "==> Installing the real sites"
sed -e "s/__API_DOMAIN__/${API_DOMAIN}/g" -e "s/__FRONTEND_DOMAIN__/${FRONTEND_DOMAIN}/g" nginx/api.conf > "$API_SITE"
sed -e "s/__API_DOMAIN__/${API_DOMAIN}/g" -e "s/__FRONTEND_DOMAIN__/${FRONTEND_DOMAIN}/g" nginx/frontend.conf > "$FRONT_SITE"
nginx -t && systemctl reload nginx

echo "==> Reload nginx automatically after each certificate renewal"
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
systemctl enable --now certbot.timer 2>/dev/null || true
certbot renew --dry-run

echo
echo "==> Done"
echo "    API:      https://${API_DOMAIN}/api/health"
echo "    Frontend: https://${FRONTEND_DOMAIN}   (upload a build with the frontend repo's deploy-server script)"
