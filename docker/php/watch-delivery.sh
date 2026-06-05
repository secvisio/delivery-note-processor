#!/usr/bin/env bash

set -e

# Technical source-of-truth folder: the scanner AND web-frontend uploads both
# write here, and this is the only folder the processor reads from. It is the
# 'source' subfolder of the delivery_notes disk (storage/delivery-notes). The
# human-visible archive (/mnt/laufwerk/ScannerOriginale) is written separately
# by the processor and must NOT be watched here.
WATCH_DIR=/var/www/delivery-note-processor/storage/delivery-notes/source

# inotifywait fails on a missing directory; make sure it exists before watching.
mkdir -p "${WATCH_DIR}"

# TEMP DIAGNOSTIC: record exactly which directory and events the watcher uses.
echo "[watch-delivery] watching: ${WATCH_DIR} (events: close_write)"

# close_write fires for both in-place writes (web uploads via Storage::put) and
# files the scanner finishes writing into the folder.
/usr/bin/inotifywait -m -e close_write --format '%f' "${WATCH_DIR}" \
    | /usr/bin/xargs --max-args=1 -I{} /usr/bin/php /var/www/delivery-note-processor/artisan app:process-file "{}"
