<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CreditCardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Import\ContaImportController;
use App\Http\Controllers\Import\FaturaImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/accounts/{id}', [AccountController::class, 'show'])->name('accounts.show');
    Route::get('/cards/{id}', [CreditCardController::class, 'show'])->name('cards.show');

    Route::prefix('import/conta')->name('import.conta.')->group(function (): void {
        Route::get('/', [ContaImportController::class, 'show'])->name('show');
        Route::post('/preview', [ContaImportController::class, 'preview'])->name('preview');
        Route::post('/commit', [ContaImportController::class, 'commit'])->name('commit');
    });

    Route::prefix('import/fatura')->name('import.fatura.')->group(function (): void {
        Route::get('/', [FaturaImportController::class, 'show'])->name('show');
        Route::post('/preview', [FaturaImportController::class, 'preview'])->name('preview');
        Route::post('/commit', [FaturaImportController::class, 'commit'])->name('commit');
    });
});
