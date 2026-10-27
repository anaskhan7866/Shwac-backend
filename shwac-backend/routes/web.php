<?php

use App\Http\Controllers\PublicInvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public invoice link sent on WhatsApp (no login): GST invoice + free UPI payment.
Route::get('/i/{token}', [PublicInvoiceController::class, 'show']);
Route::post('/i/{token}/paid', [PublicInvoiceController::class, 'claim'])->middleware('throttle:5,10');
Route::get('/i/{token}/pay-online', [PublicInvoiceController::class, 'gateway']);

// Admin panel (single page; talks to /api/v1/admin with a staff login token).
Route::get('/admin', function () {
    $v = @filemtime(public_path('admin-assets/core.js')) ?: 1;

    return response()->view('admin', ['v' => $v])->header('X-Frame-Options', 'DENY');
});

// Free app distribution: put the APKs in public/downloads/ and share yourdomain/apps
Route::get('/apps', function () {
    $files = collect(glob(public_path('downloads/*.apk')) ?: [])->map(fn ($f) => [
        'name' => basename($f), 'size' => round(filesize($f) / 1048576, 1), 'date' => date('d M Y', filemtime($f)),
    ])->sortByDesc('date')->values();

    return view('apps', ['files' => $files]);
});
