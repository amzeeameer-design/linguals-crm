<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\AdminOnly;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? url(auth()->user()->role === 'admin' ? 'workspace' : 'portal') : route('login')));
Route::view('/apply', 'public-application')->name('public.application');
Route::post('/apply', [\App\Http\Controllers\TalentIntakeController::class, 'store'])->middleware('throttle:10,1');
Route::middleware(['signed', 'throttle:30,1'])->group(function () {
    Route::get('/apply/services/{reference}', [\App\Http\Controllers\TalentIntakeController::class, 'details'])->name('talent.details');
    Route::post('/apply/services/{reference}', [\App\Http\Controllers\TalentIntakeController::class, 'saveDetails']);
    Route::get('/onboarding/{reference}', [\App\Http\Controllers\TalentIntakeController::class, 'onboarding'])->name('talent.onboarding');
    Route::post('/onboarding/{reference}', [\App\Http\Controllers\TalentIntakeController::class, 'register'])->middleware('guest');
});
Route::middleware('guest')->group(function () {
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset', ['token' => $token]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/portal', [WorkspaceController::class, 'applicant'])->middleware('auth')->name('portal');
Route::get('/workspace', [WorkspaceController::class, 'admin'])->middleware(['auth', AdminOnly::class]);
Route::middleware(['auth', AdminOnly::class])->prefix('admin')->group(function () {
    Route::post('/applications/{application}/photo', [\App\Http\Controllers\ApplicantDataController::class, 'photo'])->middleware('throttle:10,1');
    Route::get('/applications/{application}/delete', [\App\Http\Controllers\ApplicantDataController::class, 'confirm']);
    Route::post('/applications/{application}/delete', [\App\Http\Controllers\ApplicantDataController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('/', [AdminController::class, 'index'])->name('admin.index');
    Route::get('/export', [AdminController::class, 'export']);
    Route::get('/applications/{application}', [AdminController::class, 'show'])->name('admin.show');
    Route::post('/applications/{application}/review', [AdminController::class, 'review']);
});

Route::get('/profile-photo/{reference}', [WorkspaceController::class, 'profilePhoto'])->middleware('auth');
Route::post('/portal/professional-profile', [WorkspaceController::class, 'saveProfessionalProfile'])->middleware(['auth','throttle:10,1']);

Route::middleware('auth')->group(function () {
    Route::post('/portal/password', [\App\Http\Controllers\PortalOperationsController::class, 'password'])->middleware('throttle:5,1');
    Route::post('/portal/preferences', [\App\Http\Controllers\PortalOperationsController::class, 'preferences']);
    Route::post('/portal/profile', [\App\Http\Controllers\PortalOperationsController::class, 'profile']);
    Route::post('/portal/services', [\App\Http\Controllers\PortalOperationsController::class, 'services']);
    Route::get('/portal/service-details', [\App\Http\Controllers\TalentIntakeController::class, 'portalDetails']);
    Route::post('/portal/service-details', [\App\Http\Controllers\TalentIntakeController::class, 'portalSaveDetails'])->middleware('throttle:10,1');
    Route::get('/portal/export', [\App\Http\Controllers\PortalOperationsController::class, 'export']);
    Route::get('/portal/messages', [\App\Http\Controllers\PortalOperationsController::class, 'messages']);
    Route::post('/portal/messages/{application}', [\App\Http\Controllers\PortalOperationsController::class, 'message'])->middleware('throttle:20,1');
    Route::get('/portal/agreements', [\App\Http\Controllers\AgreementController::class, 'index']);
    Route::get('/agreements/{id}/file', [\App\Http\Controllers\PortalOperationsController::class, 'ndaFile'])->whereNumber('id');
    Route::post('/agreements/{id}/submit', [\App\Http\Controllers\PortalOperationsController::class, 'ndaSubmit'])->whereNumber('id')->middleware('throttle:5,1');
    Route::get('/documents/{id}', [\App\Http\Controllers\PortalManagementController::class, 'preview'])->whereNumber('id');
    Route::get('/documents/{id}/file', [\App\Http\Controllers\PortalManagementController::class, 'file'])->whereNumber('id');
    Route::get('/documents/{id}/pdf', [\App\Http\Controllers\PortalManagementController::class, 'pdf'])->whereNumber('id');
    Route::post('/documents/{id}/archive', [\App\Http\Controllers\PortalManagementController::class, 'archive'])->whereNumber('id');
    Route::get('/portal/files', [\App\Http\Controllers\PortalManagementController::class, 'files']);
    Route::post('/portal/documents', [\App\Http\Controllers\PortalManagementController::class, 'upload'])->middleware('throttle:10,1');
});
Route::middleware(['auth', AdminOnly::class])->prefix('admin')->group(function () {
    Route::get('/applications/{application}/edit', [\App\Http\Controllers\PortalOperationsController::class, 'edit']);
    Route::post('/applications/{application}/edit', [\App\Http\Controllers\PortalOperationsController::class, 'save']);
    Route::post('/applications/{application}/kyc', [\App\Http\Controllers\PortalOperationsController::class, 'kyc']);
    Route::get('/messages/{application?}', [\App\Http\Controllers\PortalOperationsController::class, 'messages']);
    Route::get('/agreements', [\App\Http\Controllers\AgreementController::class, 'index']);
    Route::post('/agreements/template', [\App\Http\Controllers\PortalOperationsController::class, 'ndaTemplate']);
    Route::post('/agreements/issue', [\App\Http\Controllers\AgreementController::class, 'issue']);
    Route::get('/agreements/templates/{template}', [\App\Http\Controllers\AgreementController::class, 'editor']);
    Route::post('/agreements/templates/{template}', [\App\Http\Controllers\AgreementController::class, 'saveTemplate']);
    Route::get('/agreements/templates/{template}/preview', [\App\Http\Controllers\AgreementController::class, 'preview']);
    Route::post('/agreements/{id}/review', [\App\Http\Controllers\PortalOperationsController::class, 'ndaReview'])->whereNumber('id');
    Route::get('/settings', [\App\Http\Controllers\PortalManagementController::class, 'settings']);
    Route::get('/readiness', [\App\Http\Controllers\PortalManagementController::class, 'readiness']);
    Route::get('/files', [\App\Http\Controllers\PortalManagementController::class, 'files']);
    Route::post('/files', [\App\Http\Controllers\PortalManagementController::class, 'upload'])->middleware('throttle:10,1');
    Route::post('/settings', [\App\Http\Controllers\PortalManagementController::class, 'saveSettings']);
    Route::post('/settings/smtp-test', [\App\Http\Controllers\PortalManagementController::class, 'testSmtp'])->middleware('throttle:3,1');
    Route::post('/settings/retry-mail', [\App\Http\Controllers\PortalManagementController::class, 'retryMail'])->middleware('throttle:5,1');
    Route::post('/email-templates/{stage}', [\App\Http\Controllers\PortalManagementController::class, 'saveTemplate']);
    Route::get('/email-templates/{stage}/preview', [\App\Http\Controllers\PortalManagementController::class, 'previewTemplate']);
    Route::post('/applications/{application}/email', [\App\Http\Controllers\PortalManagementController::class, 'sendEmail'])->middleware('throttle:10,1');
    Route::get('/applications/{application}/service-review', [\App\Http\Controllers\PortalManagementController::class, 'serviceReview']);
    Route::post('/applications/{application}/service-review', [\App\Http\Controllers\PortalManagementController::class, 'saveServiceReview']);
    Route::post('/documents/{id}/assess', [\App\Http\Controllers\PortalManagementController::class, 'assess'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/documents/{id}/review', [\App\Http\Controllers\PortalManagementController::class, 'reviewDocument'])->whereNumber('id');
});

Route::get('/launch', fn () => redirect(url(auth()->user()->role === 'admin' ? 'workspace' : 'portal')))->middleware('auth');

Route::middleware(['auth', AdminOnly::class])->group(function () {
    Route::get('/admin/notifications/{application?}', [\App\Http\Controllers\PortalOperationsController::class, 'messages']);
    Route::post('/admin/notifications', [\App\Http\Controllers\PortalOperationsController::class, 'sendNotification'])->middleware('throttle:20,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/portal/notifications', [\App\Http\Controllers\PortalOperationsController::class, 'messages']);
    Route::post('/portal/notifications/{id}/status', [\App\Http\Controllers\PortalOperationsController::class, 'notificationStatus'])->whereNumber('id');
});

Route::middleware('auth')->group(function () {
    Route::get('/agreements/{id}/sign', [\App\Http\Controllers\AgreementController::class, 'signing'])->whereNumber('id');
    Route::post('/agreements/{id}/sign', [\App\Http\Controllers\AgreementController::class, 'sign'])->whereNumber('id')->middleware('throttle:5,1');
});
