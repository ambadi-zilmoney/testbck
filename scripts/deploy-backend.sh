#!/usr/bin/env bash
# Zero-downtime backend redeploy.
# Rebuilds the image, then replaces the API replicas ONE AT A TIME, waiting for each
# to pass its health check before touching the next. nginx retries requests on the
# other replica the whole time, so users never see an outage.
#
# Usage (on the Docker host, from the project root):  ./scripts/deploy-backend.sh
set -euo pipefail
cd "$(dirname "$0")/.."

HEALTH_TIMEOUT=120

wait_healthy() {
  local service="$1" id status
  id="$(docker compose ps -q "$service")"
  for ((i = 0; i < HEALTH_TIMEOUT; i += 2)); do
    status="$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null || echo unknown)"
    if [[ "$status" == "healthy" ]]; then
      echo "    $service is healthy"
      return 0
    fi
    sleep 2
  done
  echo "!!  $service did not become healthy within ${HEALTH_TIMEOUT}s - aborting." >&2
  echo "    The other replica is still serving traffic. Check: docker compose logs $service" >&2
  exit 1
}

echo "==> Building new backend image"
docker compose build backend-a

for service in backend-a backend-b; do
  echo "==> Replacing $service"
  docker compose up -d --no-deps "$service"
  wait_healthy "$service"
done

echo "==> Ensuring the rest of the stack is up"
docker compose up -d

echo "==> Done"
docker compose ps
