<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

// Hospedagem compartilhada sem SSH (ADR-0005): o document root público fica
// como pasta irmã de tudo mais, não como subpasta ("public/") do app. O
// servidor web já aponta DOCUMENT_ROOT pra pasta certa em qualquer um dos
// dois layouts — inclusive no `php artisan serve` local — então usamos ele
// em vez de assumir basePath('public').
if (! empty($_SERVER['DOCUMENT_ROOT']) && is_dir($_SERVER['DOCUMENT_ROOT'])) {
    $app->usePublicPath($_SERVER['DOCUMENT_ROOT']);
}

return $app;
