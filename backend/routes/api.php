<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DmLogController;
use App\Http\Controllers\Api\DmTemplateController;
use App\Http\Controllers\Api\HashtagController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProspectController;
use App\Http\Controllers\Api\SafetyEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 認証エンドポイント (Sanctum)
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

/*
|--------------------------------------------------------------------------
| 認証必須 API (Bearer Token)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
        ->name('dashboard.summary');

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::get('/accounts/{account}', [AccountController::class, 'show']);
    Route::patch('/accounts/{account}', [AccountController::class, 'update']);
    Route::post('/accounts/{account}/pause', [AccountController::class, 'pause']);
    Route::post('/accounts/{account}/resume', [AccountController::class, 'resume']);

    Route::get('/prospects', [ProspectController::class, 'index']);
    Route::patch('/prospects/{prospect}', [ProspectController::class, 'update']);
    Route::delete('/prospects/{prospect}', [ProspectController::class, 'destroy']);

    Route::get('/dm-logs', [DmLogController::class, 'index']);

    Route::get('/dm-templates', [DmTemplateController::class, 'index']);
    Route::post('/dm-templates', [DmTemplateController::class, 'store']);
    Route::patch('/dm-templates/{template}', [DmTemplateController::class, 'update']);

    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::post('/posts/upload-image', [PostController::class, 'uploadImage']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

    Route::get('/hashtags', [HashtagController::class, 'index']);
    Route::post('/hashtags', [HashtagController::class, 'store']);
    Route::delete('/hashtags/{hashtag}', [HashtagController::class, 'destroy']);

    Route::get('/safety-events', [SafetyEventController::class, 'index']);
});
