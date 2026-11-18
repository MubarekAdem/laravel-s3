<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\S3Controller;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('home');
Route::get('/robots.txt', [DiscoveryController::class, 'robots'])->name('discovery.robots');
Route::get('/sitemap.xml', [DiscoveryController::class, 'sitemap'])->name('discovery.sitemap');
Route::view('/api/docs', 'api.docs')->name('api.docs');
Route::get('/api/openapi.json', [ApiDocumentationController::class, 'openapi'])->name('api.openapi');
Route::get('/llms.txt', [ApiDocumentationController::class, 'index'])->name('ai.index');
Route::get('/llms-full.txt', [ApiDocumentationController::class, 'full'])->name('ai.full');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->middleware('throttle:10,1')->name('profile.password');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/buckets', [DashboardController::class, 'storeBucket']);
    Route::get('/buckets/{bucket:name}', [DashboardController::class, 'showBucket']);
    Route::patch('/buckets/{bucket:name}', [DashboardController::class, 'updateBucket']);
    Route::delete('/buckets/{bucket:name}', [DashboardController::class, 'destroyBucket']);
    Route::get('/buckets/{bucket:name}/objects/{object}/download', [DashboardController::class, 'downloadObject'])->scopeBindings();
    Route::post('/buckets/{bucket:name}/objects/{object}/restore', [DashboardController::class, 'restoreObject'])->scopeBindings();
    Route::delete('/buckets/{bucket:name}/objects/{object}/purge', [DashboardController::class, 'purgeObject'])->scopeBindings();
    Route::delete('/buckets/{bucket:name}/objects/{object}', [DashboardController::class, 'destroyObject'])->scopeBindings();
    Route::post('/keys', [DashboardController::class, 'key']);
    Route::patch('/keys/{key}', [DashboardController::class, 'updateKey']);
    Route::delete('/keys/{key}', [DashboardController::class, 'revoke']);
    Route::post('/logout', [AuthController::class, 'destroy']);
});
Route::prefix('s3')->middleware(['s3.errors', 's3.signature'])->group(function () {
    Route::get('/', [S3Controller::class, 'buckets']);
    Route::put('/{bucket}', [S3Controller::class, 'createBucket']);
    Route::post('/{bucket}', [S3Controller::class, 'deleteObjects']);
    Route::match(['HEAD'], '/{bucket}', [S3Controller::class, 'headBucket']);
    Route::delete('/{bucket}', [S3Controller::class, 'deleteBucket']);
    Route::get('/{bucket}', [S3Controller::class, 'list']);
    Route::put('/{bucket}/{key}', [S3Controller::class, 'put'])->where('key', '.*');
    Route::post('/{bucket}/{key}', [S3Controller::class, 'post'])->where('key', '.*');
    Route::match(['HEAD'], '/{bucket}/{key}', [S3Controller::class, 'head'])->where('key', '.*');
    Route::get('/{bucket}/{key}', [S3Controller::class, 'get'])->where('key', '.*');
    Route::delete('/{bucket}/{key}', [S3Controller::class, 'delete'])->where('key', '.*');
});
