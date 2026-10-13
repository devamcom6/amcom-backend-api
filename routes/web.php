<?php

use Illuminate\Support\Facades\Route;

// API-only service. The root returns a minimal JSON descriptor (no HTML views).
Route::get('/', fn () => response()->json([
    'name' => config('app.name').' API',
    'version' => 'v1',
    'base_url' => url('/api/v1'),
]));
