#!/usr/bin/env bash

set -e
#/usr/bin/inotifywait -m -e close_write --format '%f' /var/www/storage/delivery-notes/source \
#    | /usr/bin/xargs --max-args=1 -I{} /usr/local/bin/php /var/www/artisan app:process-file "{}"


/usr/bin/inotifywait -m -e close_write --format '%f' /var/www/storage/delivery-notes/source \
| while read FILE; do
    FULL_PATH="/var/www/storage/delivery-notes/source/$FILE"
    sleep 2
    SIZE1=$(stat -c%s "$FULL_PATH")
    sleep 1
    SIZE2=$(stat -c%s "$FULL_PATH")
    if [ "$SIZE1" -eq "$SIZE2" ]; then
        /usr/local/bin/php /var/www/artisan app:process-file "$FILE"
    fi
done