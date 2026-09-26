<?php

declare(strict_types=1);

use App\Domain\Ops\Console\CheckDetectiveControls;
use App\Domain\Ops\Console\SummariseQuality;
use App\Http\Middleware\EnsureTokenDevice;
use App\Http\Middleware\LogRecordAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    // Commands live in their domain module, not app/Console/Commands, so
    // auto-discovery does not find them.
    ->withCommands([
        CheckDetectiveControls::class,
        SummariseQuality::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Terminable: it writes after the response has been sent, so a chart
            // view is never slowed down by its own access log.
            'log.access' => LogRecordAccess::class,
            'device' => EnsureTokenDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
