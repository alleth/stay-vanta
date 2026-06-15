#!/bin/sh
set -e

# Railway injects $PORT; fall back to 8080 for local `docker run`.
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/__PORT__/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Apply database migrations (idempotent — only new migrations run).
# A transient DB hiccup shouldn't crash-loop the web server, so we log and
# carry on. For zero-downtime correctness, prefer a Railway "pre-deploy
# command" running this instead (see DEPLOYMENT.md).
php bin/cake.php migrations migrate --no-lock || echo "WARN: migrations did not run"

exec apache2-foreground
