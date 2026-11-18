<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\S3Controller;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::post('/buckets', [DashboardController::class, 'storeBucket']);
    Route::post('/keys', [DashboardController::class, 'key']);
    Route::delete('/keys/{key}', [DashboardController::class, 'revoke']);
    Route::post('/logout', [AuthController::class, 'destroy']);
});
Route::prefix('s3')->middleware('s3.signature')->group(function () {
    Route::get('/', [S3Controller::class, 'buckets']);
    Route::put('/{bucket}', [S3Controller::class, 'createBucket']);
    Route::delete('/{bucket}', [S3Controller::class, 'deleteBucket']);
    Route::get('/{bucket}', [S3Controller::class, 'list']);
    Route::put('/{bucket}/{key}', [S3Controller::class, 'put'])->where('key', '.*');
    Route::post('/{bucket}/{key}', [S3Controller::class, 'post'])->where('key', '.*');
    Route::match(['HEAD'], '/{bucket}/{key}', [S3Controller::class, 'head'])->where('key', '.*');
    Route::get('/{bucket}/{key}', [S3Controller::class, 'get'])->where('key', '.*');
    Route::delete('/{bucket}/{key}', [S3Controller::class, 'delete'])->where('key', '.*');
});
