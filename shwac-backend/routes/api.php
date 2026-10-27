<?php

use Illuminate\Support\Facades\Route;

$registerApi = require __DIR__.'/api_routes.php';

// Support standard /api/v1/...
Route::prefix('v1')->group($registerApi);

// Support /api/... without v1
Route::group([], $registerApi);

return $registerApi;
