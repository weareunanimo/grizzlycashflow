<?php

namespace App\Providers;

use App\Support\ReviewQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Carbon não segue o locale do Laravel sozinho — sem isso, translatedFormat()
        // (nomes de mês em ->cards/show, ->closing/index etc.) sai sempre em inglês.
        Carbon::setLocale(config('app.locale'));

        View::composer('layouts.app', function ($view): void {
            // Conta grupos, a mesma unidade que a tela Revisar mostra — contando
            // lançamentos, o badge diria 40 e a tela listaria 12.
            $count = Auth::check() ? ReviewQueue::groupCount((int) Auth::id()) : 0;

            $view->with('pendingReviewCount', $count);
        });
    }
}
