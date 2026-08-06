<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClosingController;
use App\Http\Controllers\CreditCardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Import\ContaImportController;
use App\Http\Controllers\Import\FaturaImportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NewAccountController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/bancos', [BankController::class, 'index'])->name('banks.index');
    Route::patch('/bancos/{id}', [BankController::class, 'update'])->name('banks.update');
    Route::delete('/bancos/{id}', [BankController::class, 'destroy'])->name('banks.destroy');
    Route::patch('/lancamentos/{id}/categoria', [BankController::class, 'updateTransactionCategory'])->name('transactions.category');
    Route::get('/cartoes', [CreditCardController::class, 'index'])->name('cards.index');
    Route::patch('/cartoes/{key}', [CreditCardController::class, 'update'])->name('cards.update');
    Route::delete('/cartoes/{key}', [CreditCardController::class, 'destroy'])->name('cards.destroy');
    Route::patch('/compras/{id}/categoria', [CreditCardController::class, 'updatePurchaseCategory'])->name('purchases.category');

    Route::get('/accounts/new', [NewAccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [NewAccountController::class, 'store'])->name('accounts.store');

    Route::get('/categorias', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categorias', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('/categorias/{id}', [CategoryController::class, 'update'])->name('categories.update');

    Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
    Route::post('/review/{kind}/{id}', [ReviewController::class, 'store'])->name('review.store');

    Route::get('/fechamento', [ClosingController::class, 'index'])->name('closing.index');

    Route::get('/importar', [ImportController::class, 'index'])->name('import.index');

    // Fatura do cartão de crédito e extrato do cartão de benefícios entram pelo
    // mesmo formulário; o controller escolhe o parser pelo tipo do cartão.
    Route::post('/importar/cartao/preview', [ImportController::class, 'previewCard'])->name('import.cartao.preview');

    Route::prefix('import/conta')->name('import.conta.')->group(function (): void {
        Route::post('/preview', [ContaImportController::class, 'preview'])->name('preview');
        Route::post('/commit', [ContaImportController::class, 'commit'])->name('commit');
    });

    Route::prefix('import/fatura')->name('import.fatura.')->group(function (): void {
        Route::post('/preview', [FaturaImportController::class, 'preview'])->name('preview');
        Route::post('/commit', [FaturaImportController::class, 'commit'])->name('commit');
    });
});
