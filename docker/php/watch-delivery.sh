#!/usr/bin/env bash

set -e

# Technical source-of-truth folder: the scanner AND web-frontend uploads both
# write here, and this is the only folder the processor reads from. It is the
# 'source' subfolder of the delivery_notes disk (storage/delivery-notes). The
# human-visible archive (/mnt/laufwerk/ScannerOriginale) is written separately
# by the processor and must NOT be watched here.
WATCH_DIR=/var/www/delivery-note-processor/storage/delivery-notes/source

# Per-filename locks debounce duplicate close_write events for the same file.
# This is the SECONDARY guard; Laravel (fileArrived) is the primary one.
LOCK_DIR=/tmp/delivery-note-watcher-locks

# inotifywait fails on a missing directory; make sure both exist before watching.
mkdir -p "${WATCH_DIR}" "${LOCK_DIR}"

# TEMP DIAGNOSTIC: record exactly which directory and events the watcher uses.
echo "[watch-delivery] watching: ${WATCH_DIR} (events: close_write)"

# close_write fires for both in-place writes (web uploads via Storage::put) and
# files the scanner finishes writing into the folder. `mkdir` is atomic, so it
# doubles as a lock: only the first event for a given filename acquires it.
/usr/bin/inotifywait -m -e close_write --format '%f' "${WATCH_DIR}" | while read -r FILE; do
    # Skip empty names and basic path-traversal safety (inotify %f is a basename).
    [ -n "${FILE}" ] || continue

    LOCK="${LOCK_DIR}/${FILE//\//_}.lock"

    if mkdir "${LOCK}" 2>/dev/null; then
        (
            # Let the file fully settle before dispatching.
            sleep 2
            /usr/bin/php /var/www/delivery-note-processor/artisan app:process-file "${FILE}" \
                || echo "[watch-delivery] processing failed for ${FILE}"
            rmdir "${LOCK}" 2>/dev/null || true
        ) &
    else
        echo "[watch-delivery] skipped duplicate event for ${FILE}"
    fi
done
