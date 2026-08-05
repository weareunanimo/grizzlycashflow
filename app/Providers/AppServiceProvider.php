<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            $count = 0;

            if (Auth::check()) {
                $count = DB::table('transactions')
                    ->where('user_id', Auth::id())
                    ->where('needs_review', true)
                    ->whereNull('deleted_at')
                    ->count();
            }

            $view->with('pendingReviewCount', $count);
        });
    }
}
