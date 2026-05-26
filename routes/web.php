<?php

use Illuminate\Support\Facades\Route;
use TIVENTS\LogtoLaravelSdk\Controllers\AuthController;

// Only register routes if they haven't been registered already
if (!Route::hasNamedRoute('logto.callback')) {
    Route::group([
        'prefix' => 'auth/logto',
        'middleware' => ['web'],
    ], function () {
        // Authorization callback
        Route::get('/callback', [AuthController::class, 'callback'])
            ->name('logto.callback');

        // Logout
        Route::get('/logout', [AuthController::class, 'logout'])
            ->name('logto.logout')
            ->middleware(['auth:' . config('logto.guard.name', 'logto')]);

        // Redirect to Logto for login
        Route::get('/login', [AuthController::class, 'redirectToLogto'])
            ->name('logto.login')
            ->middleware('guest:' . config('logto.guard.name', 'logto'));

        // API endpoints (for authenticated users)
        Route::group([
            'prefix' => 'api',
            'middleware' => ['auth:' . config('logto.guard.name', 'logto')],
        ], function () {
            Route::get('/user', [AuthController::class, 'userInfo'])
                ->name('logto.api.user');

            Route::post('/refresh', [AuthController::class, 'refreshToken'])
                ->name('logto.api.refresh');
        });
    });
}
