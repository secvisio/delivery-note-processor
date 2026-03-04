<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/upload', [UploadController::class, 'upload'])
    ->name('upload');
