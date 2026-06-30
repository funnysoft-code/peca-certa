<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust all proxies and forward all X-Forwarded-* headers,
        // including X-Forwarded-Host used by AWS ELB.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Keep appearance + sidebar_state unencrypted (JS reads them directly).
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            HandlePrecognitiveRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Redirect on CSRF token expiry with a user-facing message.
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 419) {
                return back()->withInput()->with(
                    'error',
                    'Your session has expired. Please try again.',
                );
            }

            return $response;
        });

        // Map HTTP exceptions to the shared Inertia 'error' page (Inertia v3 API).
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ExceptionResponse {
            if (in_array($response->statusCode(), [403, 404, 405, 429, 500, 503], strict: true)) {
                return $response->render('error', ['status' => $response->statusCode()]);
            }

            return $response;
        });
    })->create();
