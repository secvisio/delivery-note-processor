<?php
return [

    /*
    |--------------------------------------------------------------------------
    | Technical source-of-truth folder for incoming files
    |--------------------------------------------------------------------------
    |
    | Both the scanner and web-frontend uploads drop files into this folder on
    | the delivery_notes disk, and the inotify watcher / processor read from it.
    | It is the fixed technical input folder. Keep it 'source'.
    |
    */

    'source_folder' => 'source',

    /*
    |--------------------------------------------------------------------------
    | Where to save renamed scanned files
    |--------------------------------------------------------------------------
    |
    | The target folder within a disk
    |
    */

    'target_folder' => env('DELIVERY_NOTES_TARGET_FOLDER', 'target'),

    /*
    |--------------------------------------------------------------------------
    | Where to save renamed scanned files
    |--------------------------------------------------------------------------
    |
    | The unknown target folder within a disk
    |
    */

    'unknown_folder' => env('DELIVERY_NOTES_UNKNOWN_FOLDER', 'Nicht zugeordnet'),

    /*
    |--------------------------------------------------------------------------
    | Where to save renamed production-order files
    |--------------------------------------------------------------------------
    |
    | Files identified as production orders ("Produktionsaufträge") are
    | routed here instead of the delivery-note target folder.
    |
    */

    'production_order_folder' => env('PRODUCT_ORDERS_TARGET_FOLDER', 'Produktionsaufträge'),

    /*
    |--------------------------------------------------------------------------
    | Where to save renamed Frachtbrief files
    |--------------------------------------------------------------------------
    |
    | Files identified as freight waybills ("Frachtbriefe") are routed here
    | instead of the delivery-note target folder. A confirmed Frachtbrief whose
    | order number could NOT be extracted goes to `unknown_folder` instead —
    | the order number is the document's primary identifier.
    |
    | Within this base folder, confirmed Frachtbriefe are filed into fixed,
    | customer-specific subfolders decided deterministically in PHP from the
    | recipient company name (DeliveryNoteProcessorService::resolveFrachtbrief-
    | Subfolder): `Rhenus` and `Sonstige`. These subfolders are NOT separate
    | env vars. For the fixed production path (/mnt/laufwerk/Frachtbriefe) both
    | subfolders must exist before deployment — the application never creates
    | or chmods them:
    |     /mnt/laufwerk/Frachtbriefe/Rhenus
    |     /mnt/laufwerk/Frachtbriefe/Sonstige
    |
    | A `UPS` subfolder existed previously and is no longer written to; UPS
    | recipients now go to `Sonstige`. Any existing UPS folder and the files
    | already in it are left alone — nothing is migrated or rewritten.
    |
    */

    'frachtbrief_folder' => env('FRACHTBRIEFE_TARGET_FOLDER', 'Frachtbriefe'),

    /*
    |--------------------------------------------------------------------------
    | The disk name of saved files
    |--------------------------------------------------------------------------
    |
    | The disk itself
    |
    */

    'delivery_notes_disk' => env('DELIVERY_NOTES_DISK', 'delivery_notes'),


    /*
    |--------------------------------------------------------------------------
    | How much items to show per page
    |--------------------------------------------------------------------------
    |
    | The paginator
    |
    */
    'delivery_notes_items_per_page' => env('DELIVERY_NOTES_ITEMS_PER_PAGE', 5),
];
