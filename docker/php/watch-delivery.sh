#!/usr/bin/env bash

set -e

# Resolve the watched directory from the same Laravel config the app/processor use,
# so the upload target, the watched folder, and ProcessFile all agree in every env.
WATCH_DIR=$(/usr/local/bin/php /var/www/delivery-note-processor/artisan delivery-note:source-path)

# inotifywait fails on a missing directory; make sure it exists before watching.
mkdir -p "${WATCH_DIR}"

# TEMP DIAGNOSTIC: record exactly which directory and events the watcher uses.
echo "[watch-delivery] watching: ${WATCH_DIR} (events: close_write)"

/usr/bin/inotifywait -m -e close_write --format '%f' "${WATCH_DIR}" \
    | /usr/bin/xargs --max-args=1 -I{} /usr/local/bin/php /var/www/delivery-note-processor/artisan app:process-file "{}"
