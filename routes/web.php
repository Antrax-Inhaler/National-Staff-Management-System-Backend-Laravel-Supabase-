<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-log', function () {
    Log::info('🚀 Test log entry working!');
    return 'Log written';
});
