<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\ChecklistController;
use App\Http\Controllers\API\ApplicationFormController;

// Authentication
Route::post('/register', [UserController::class, 'register']);
Route::post('/login', [UserController::class, 'login']);
Route::post('/logout', [UserController::class, 'logout']);

// Admin Checklist
Route::post('/checklist/save', [ChecklistController::class, 'save']);
Route::get('/checklist/load', [ChecklistController::class, 'load']);

// Application Form
Route::post('/application/save', [ApplicationFormController::class, 'save']);
Route::get('/application/load', [ApplicationFormController::class, 'load']);
Route::get('/application/placeholders', [ApplicationFormController::class, 'placeholdersSmart']);
Route::post('/application/generate-doc', [ApplicationFormController::class, 'generateDoc']);
Route::post('/application/preview-pdf', [ApplicationFormController::class, 'previewPdf']);
Route::post('/application/upload', [ApplicationFormController::class, 'upload']);
Route::get('/application/files', [ApplicationFormController::class, 'files']);
Route::post('/application/start', [ApplicationFormController::class, 'start']);
Route::get('/application/status', [ApplicationFormController::class, 'status']);
Route::post('/application/submit', [ApplicationFormController::class, 'submit']);
// Final Permit (admin-provided)
Route::post('/application/permit/upload', [ApplicationFormController::class, 'uploadPermit']);
Route::get('/application/permit/download', [ApplicationFormController::class, 'downloadPermit']);
// Admin-provided reference files for applicants
Route::post('/application/admin-files/upload', [ApplicationFormController::class, 'uploadAdminFiles']);
Route::get('/application/admin-files', [ApplicationFormController::class, 'adminFiles']);
Route::get('/application/admin-files/download', [ApplicationFormController::class, 'downloadAdminFile']);
