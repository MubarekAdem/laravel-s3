<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\S3Controller;

Route::get('/', function () {
    return view('dashboard');
});
Route::prefix('s3')->middleware('s3.signature')->group(function () {Route::get('/',[S3Controller::class,'buckets']);Route::put('/{bucket}',[S3Controller::class,'createBucket']);Route::get('/{bucket}',[S3Controller::class,'list']);Route::put('/{bucket}/{key}',[S3Controller::class,'put'])->where('key','.*');Route::get('/{bucket}/{key}',[S3Controller::class,'get'])->where('key','.*');Route::delete('/{bucket}/{key}',[S3Controller::class,'delete'])->where('key','.*');});
