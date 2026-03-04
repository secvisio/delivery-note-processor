#!/usr/bin/env bash

set -e
/usr/bin/inotifywait -m -e close_write --format '%f' /var/www/storage/delivery-notes/source \
    | /usr/bin/xargs --max-args=1 -I{} /usr/local/bin/php /var/www/artisan app:process-file "{}"
