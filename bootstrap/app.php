<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use App\Helpers\ApiResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'user' => \App\Http\Middleware\EnsureUser::class,
            'vendor' => \App\Http\Middleware\EnsureVendor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Email ALERT_EMAIL about real server errors (see App\Support\ErrorAlert).
        $exceptions->report(function (\Throwable $e) {
            \App\Support\ErrorAlert::report($e);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::validationError($e->errors(), 'Validation failed');
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::unauthorized('Please login to continue.');
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::notFound('Resource not found.');
            }
        });

        // abort(403/404/422) and failed policy checks keep their status code
        // instead of falling through to a 500 below.
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage() ?: 'You don’t have permission to do that.', statusCode: 403);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() ?: match ($status) {
                    403 => 'You don’t have permission to do that.',
                    404 => 'Resource not found.',
                    429 => 'Too many requests. Please slow down.',
                    default => 'Something went wrong. Please try again later.',
                };
                return ApiResponse::error($message, statusCode: $status);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $message = app()->isProduction()
                    ? 'Something went wrong. Please try again later.'
                    : $e->getMessage();
                return ApiResponse::serverError($message);
            }
        });

    })->create();
