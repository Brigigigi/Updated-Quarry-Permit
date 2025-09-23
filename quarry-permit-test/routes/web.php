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
Route::get('/home/admin/export.csv', [AdminApplicationController::class, 'export'])
    ->middleware(['web','is_admin'])
    ->name('admin.export');

Route::get('/home/admin/app/{trackingId}', [AdminApplicationController::class, 'show'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.show');

Route::post('/home/admin/app/{trackingId}', [AdminApplicationController::class, 'update'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.update');

// Admin actions: bond, board, grant permit
Route::post('/home/admin/app/{trackingId}/bond', [AdminApplicationController::class, 'saveBond'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.bond');
Route::post('/home/admin/app/{trackingId}/board', [AdminApplicationController::class, 'saveBoardAction'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.board');
Route::post('/home/admin/app/{trackingId}/grant', [AdminApplicationController::class, 'grantPermit'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.grant');
Route::post('/home/admin/app/{trackingId}/fees', [AdminApplicationController::class, 'saveFee'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.fees');
Route::post('/home/admin/app/{trackingId}/inspection', [AdminApplicationController::class, 'addInspection'])
    ->middleware(['web','is_admin'])
    ->name('admin.app.inspection');
