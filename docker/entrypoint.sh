#!/bin/sh
set -e

# Optionally run DB migrations before starting the server.
# In Kubernetes this is done by an initContainer instead, so leave it off there.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php /var/www/html/bin/migrate.php
fi

exec "$@"
