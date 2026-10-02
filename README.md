# PHP API — api.example.com

PHP 8.3 REST API running in Docker, served over HTTPS by nginx with a free Let's Encrypt certificate.
The same server (and the same nginx) also hosts the React frontend at `https://example.com`
(separate repository: the frontend's build is uploaded to `/var/www/example.com`).

```
Internet ──► nginx :443 on the EC2 instance (Let's Encrypt TLS for both domains)
             ├─ example.com      ──► /var/www/example.com/current   (static React build)
             └─ api.example.com  ──► 127.0.0.1:8081  backend-a (Docker) ─┐
                                 └─► 127.0.0.1:8082  backend-b (Docker) ─┴─► db (MySQL, Docker)
                                                                             ▲
                                                                     db-backup (daily dump)
```

## Structure

```
.
├── Dockerfile              # composer → php:8.3-apache, non-root, port 8080
├── public/index.php        # Front controller: errors, CORS, request IDs, idempotency
├── src/                    # Config, Database, Logger, Http/, Controller/, Repository/
├── bin/migrate.php         # Migration runner
├── migrations/             # *.sql, applied in order
├── docker/                 # php.ini, apache.conf, entrypoint.sh
├── docker-compose.yml      # backend-a, backend-b, db, db-backup
├── nginx/
│   ├── api.conf            # api.example.com → Docker replicas (retries, JSON errors)
│   └── frontend.conf       # example.com → static files (SPA fallback, caching, CSP)
├── scripts/
│   ├── setup-nginx.sh      # One-time: nginx + certbot + both sites
│   ├── deploy-backend.sh   # Zero-downtime API redeploy
│   └── db-backup.sh
└── .env.example
```

## Hosting both sites on one EC2 instance

### 1. Launch the instance
- **AMI:** Ubuntu Server 24.04 LTS · **Type:** t3.small or larger (2 GB RAM minimum for MySQL + 2 API replicas)
- **Storage:** 20 GB gp3
- **Security group** inbound: `22` (your IP only), `80` (anywhere), `443` (anywhere)
- **Key pair:** download the `.pem` file; you need it for SSH and for frontend deploys
- Allocate an **Elastic IP** and associate it with the instance, so the IP never changes

### 2. DNS (at your domain provider or Route 53)
| Record | Type | Value |
|---|---|---|
| `example.com` | A | Elastic IP |
| `www.example.com` | A | Elastic IP |
| `api.example.com` | A | Elastic IP |

Wait until `nslookup api.example.com` returns the IP before step 5 (certbot needs it).

### 3. Connect and install Docker
```bash
ssh -i my-key.pem ubuntu@<ELASTIC_IP>

sudo apt-get update && sudo apt-get install -y git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
exit        # log out and back in so the docker group applies
ssh -i my-key.pem ubuntu@<ELASTIC_IP>
```

### 4. Start the API
```bash
git clone https://github.com/ambadi-zilmoney/testbck.git api && cd api
cp .env.example .env
nano .env     # CORS_ALLOWED_ORIGINS=https://example.com,https://www.example.com  + strong DB passwords
docker compose up -d --build
docker compose ps                         # wait until all services are "healthy"
curl http://127.0.0.1:8081/api/health     # {"status":"ok"}
```

### 5. nginx + Let's Encrypt for both domains (one time)
```bash
chmod +x scripts/*.sh
sudo ./scripts/setup-nginx.sh api.example.com example.com you@example.com
```
This installs nginx + certbot, requests certificates for `api.example.com` and `example.com` + `www`,
installs both nginx sites, creates `/var/www/example.com` (owned by `ubuntu`, so deploys need no sudo),
and enables automatic renewal.

Check: `curl https://api.example.com/api/health` and open `https://example.com` (shows "Deploy pending").

### 6. Deploy the frontend (from your PC, in the **frontend** repo)
```powershell
.\deploy\deploy-server.ps1 -Server ubuntu@<ELASTIC_IP> -Domain example.com -KeyFile C:\path\my-key.pem
```
Open `https://example.com` — the badge should show **API online**.

## Updating

| What | Command |
|---|---|
| Backend (on the server) | `cd ~/api && git pull && ./scripts/deploy-backend.sh` — no downtime |
| Frontend (from your PC) | re-run `deploy-server.ps1` |
| nginx config | edit `nginx/*.conf`, re-run `setup-nginx.sh` (certificates are kept) |

## Operations

| Task | Command |
|---|---|
| API logs | `docker compose logs -f backend-a backend-b` |
| nginx logs | `sudo tail -f /var/log/nginx/api.access.log /var/log/nginx/frontend.access.log` |
| Run migrations by hand | `docker compose exec backend-a php bin/migrate.php` |
| Check certificate renewal | `sudo certbot renew --dry-run` |
| Restore a DB backup | `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" app' < backups/<file>.sql` |
| Roll back the frontend | `ln -sfn /var/www/example.com/releases/<older> /var/www/example.com/current` |
| Failover test | `docker compose stop backend-a` — the API keeps working through backend-b |

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
cp .env.example .env
docker compose up -d --build     # API at http://localhost:8081 and :8082
```
