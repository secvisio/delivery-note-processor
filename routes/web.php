<?php

use App\Http\Controllers\UploadController;
use App\Livewire\Companies;
use App\Livewire\CompanyAliases;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('dashboard');

Route::post('/upload', [UploadController::class, 'upload'])
    ->name('upload');

Route::get('/companies', Companies::class)->name('companies');

Route::get('/company-aliases', CompanyAliases::class)->name('company-aliases');
