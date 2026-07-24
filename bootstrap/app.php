<?php

declare(strict_types=1);

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\VerifyShippingWebhookSignature;
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
        $middleware->alias([
            'api.token' => AuthenticateApiToken::class,
            'shipping.signature' => VerifyShippingWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Domain failures are not server errors.
         *
         * Every InventoryException carries its own machine-readable code and the
         * HTTP status it should surface as, so this single handler replaces what
         * would otherwise be an instanceof ladder that grows with every new
         * exception type. A client can branch on `error` rather than parsing
         * English out of `message`.
         */
        $exceptions->render(function (InventoryException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json($exception->toArray(), $exception->httpStatus());
        });
    })->create();
