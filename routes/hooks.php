<?php
// routes/hooks.php

use App\Http\Controllers\HookController;
use App\Http\Middleware\VerifyHookToken;
use Illuminate\Support\Facades\Route;

Route::middleware(VerifyHookToken::class)
    ->prefix('hooks')
    ->group(function () {
        Route::post('ensure-project', [HookController::class, 'ensure']);
        Route::post('digest', [HookController::class, 'digest']);
        Route::post('search', [HookController::class, 'search']);
    });
