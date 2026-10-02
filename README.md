# PHP API — api.example.com

PHP 8.3 REST API running in Docker, served over HTTPS by nginx with a free Let's Encrypt certificate.
The React frontend lives in its own repository and is hosted at `https://example.com`.

```
Internet ──► nginx :443 (on the server, Let's Encrypt TLS)
             ├─► 127.0.0.1:8081  backend-a (Docker) ─┐
             └─► 127.0.0.1:8082  backend-b (Docker) ─┴─► db (MySQL, Docker, internal only)
                                                         ▲
                                                 db-backup (daily dump → ./backups)
```

## Structure

```
.
├── Dockerfile              # composer → php:8.3-apache, non-root, port 8080
├── composer.json           # PSR-4 autoload (App\ → src/)
├── public/index.php        # Front controller: errors, CORS, request IDs, idempotency
├── src/                    # Config, Database, Logger, Http/, Controller/, Repository/
├── bin/migrate.php         # Migration runner (locked, idempotent)
├── migrations/             # *.sql, applied in order
├── docker/                 # php.ini, apache.conf, entrypoint.sh
├── docker-compose.yml      # backend-a, backend-b, db, db-backup
├── nginx/api.conf          # nginx site: HTTPS, proxy to both replicas, retries
├── scripts/
│   ├── setup-nginx.sh      # One-time: install nginx + certbot, get certificate
│   ├── deploy-backend.sh   # Zero-downtime redeploy
│   └── db-backup.sh        # Used by the db-backup container
└── .env.example            # Copy to .env
```

## Hosting (Ubuntu 22.04 / 24.04 server)

**Before you start**
- A server with a public IP and ports **22, 80, 443** open.
- DNS **A record** `api.example.com` → the server's IP (wait until `nslookup api.example.com` returns it).
- Docker installed: `curl -fsSL https://get.docker.com | sudo sh && sudo usermod -aG docker $USER` (log out and back in).

**1. Get the code**
```bash
git clone https://github.com/<you>/<backend-repo>.git api && cd api
```

**2. Configure**
```bash
cp .env.example .env
nano .env
```
Set at least:
```
CORS_ALLOWED_ORIGINS=https://example.com,https://www.example.com   # your frontend domain(s), exact, no trailing slash
DB_PASSWORD=<strong password>
DB_ROOT_PASSWORD=<strong password>
```

**3. Start the API and database**
```bash
docker compose up -d --build
docker compose ps            # wait until every service shows "healthy"
curl http://127.0.0.1:8081/api/health
```

**4. nginx + Let's Encrypt (one time)**
```bash
chmod +x scripts/*.sh
sudo ./scripts/setup-nginx.sh api.example.com example.com you@example.com
#                              ^ API domain       ^ frontend domain  ^ email for certificate notices
```
This installs nginx and certbot, gets the certificate, installs `nginx/api.conf` with your domains filled in,
and enables automatic renewal (certbot's timer runs twice a day; nginx reloads after each renewal).

**5. Verify**
```bash
curl https://api.example.com/api/health
curl https://api.example.com/api/ready
curl -i -H "Origin: https://example.com" https://api.example.com/api/items   # must include Access-Control-Allow-Origin
```

## Updating the backend

```bash
git pull
./scripts/deploy-backend.sh      # rebuilds, replaces backend-a then backend-b, no downtime
```

## Operations

| Task | Command |
|---|---|
| Logs | `docker compose logs -f backend-a backend-b` |
| nginx logs | `sudo tail -f /var/log/nginx/api.access.log /var/log/nginx/api.error.log` |
| Run migrations by hand | `docker compose exec backend-a php bin/migrate.php` |
| Edit nginx config | change `nginx/api.conf`, re-run `setup-nginx.sh` (keeps the certificate) |
| Check renewal | `sudo certbot renew --dry-run` |
| Restore a backup | `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" app' < backups/<file>.sql` |
| Failover test | `docker compose stop backend-a` — API keeps working via backend-b |

## API

| Method | Path | Notes |
|---|---|---|
| GET | `/api/health` | Liveness |
| GET | `/api/ready` | Readiness (checks DB) |
| GET | `/api/items` | `?limit=50&offset=0` |
| GET | `/api/items/{id}` | |
| POST | `/api/items` | `{"name": "..."}`, optional `Idempotency-Key` header |
| DELETE | `/api/items/{id}` | |

## Local development

```bash
cp .env.example .env         # defaults are fine locally
docker compose up -d --build # API at http://localhost:8081 and :8082
```
