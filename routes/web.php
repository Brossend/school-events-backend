<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '1.0.0',
        'status' => 'running',
    ]);
});

Route::get('/health', [HealthController::class, 'check']);
