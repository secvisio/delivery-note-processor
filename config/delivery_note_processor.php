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
