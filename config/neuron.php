<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Neuron Default Values
    |--------------------------------------------------------------------------
    |
    | Neuron provider
    |
    */

    'provider' => env('NEURON_PROVIDER', 'OpenAI'),

    /*
    |--------------------------------------------------------------------------
    |
    | The OpenAi API key
    |
    */

    'openai_key' => env('NEURON_OPENAI_KEY', null),

    /*
    |--------------------------------------------------------------------------
    |
    | The OpenAi API model
    |
    */

    'openai_model' => env('NEURON_OPENAI_MODEL', 'gpt-4-mini'),
];
