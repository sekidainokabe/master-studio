<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA からの API リクエストでセッション（クッキー）と CSRF 検証を有効にする
        $middleware->statefulApi();

        // 本番では Railway のプロキシが HTTPS を終端し、X-Forwarded-* ヘッダーで元のリクエストを伝える。
        // プロキシの IP は固定でなく、アプリへはプロキシ経由でしか届かないため、すべてのプロキシを信頼する
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
