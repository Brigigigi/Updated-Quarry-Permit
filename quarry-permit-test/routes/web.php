<?php

// use Illuminate\Support\Facades\Route;

// // Landing page
// Route::get('/', function () {
//     return view('welcome');
// });

// // Dashboard (requires auth)
// Route::get('/dashboard', function () {
//     return view('dashboard');
// })->middleware(['web'])->name('dashboard');

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\AdminAuthController;
use App\Http\Controllers\Web\AdminApplicationController;

// Landing page serves the SPA index
Route::get('/', function () {
    return response()->file(public_path('index.html'));
});

// Admin auth routes (web sessions)
Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// Admin-only home
Route::get('/home/admin', [AdminApplicationController::class, 'index'])
    ->middleware(['web','is_admin'])
    ->name('admin.home');

Route::get('/home/admin/app/{trackingId}', [AdminApplicationController::class, 'show'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.show');

Route::post('/home/admin/app/{trackingId}', [AdminApplicationController::class, 'update'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.update');
