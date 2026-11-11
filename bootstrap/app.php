<?php

use App\Application\Auth\AuthFlowException;
use App\Application\Caregiving\LinkFailure;
use App\Support\AuthApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([LinkFailure::class]);
        $exceptions->render(function (LinkFailure $exception, Request $request) {
            return $request->is('api/*') ? AuthApiResponse::error($exception->machineCode, $exception->status, $exception->machineCode) : null;
        });
        $exceptions->dontReport([AuthFlowException::class]);
        $exceptions->render(function (AuthFlowException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($exception->field !== null) {
                return response()->json(['code' => 'validation-failed', 'message' => 'The submitted data is invalid.',
                    'errors' => [$exception->field => [$exception->getMessage()]],
                    'error_codes' => [$exception->field => [$exception->machineCode]]], 422, ['Cache-Control' => 'no-store, private']);
            }
            $response = AuthApiResponse::error($exception->machineCode, $exception->status, $exception->getMessage());
            if ($exception->status === 429) {
                $response->header('Retry-After', '60');
            }

            return $response;
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            return $request->is('api/*') ? AuthApiResponse::validation($exception) : null;
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return $request->is('api/*') ? AuthApiResponse::error('unauthenticated', 401, 'Authentication is required.') : null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
