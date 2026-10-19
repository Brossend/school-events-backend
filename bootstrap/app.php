<?php

use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\IdentifyTenantScope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 1. Assign trace_id and setup monolog context globally
        $middleware->prepend(AssignTraceId::class);

        // 2. Sanctum standard stateful API session pipeline for Nuxt 4 SPA
        $middleware->statefulApi();

        // 3. Prevent tenant injection globally on API group
        $middleware->api(append: [
            \App\Http\Middleware\PreventTenantInjection::class,
        ]);

        // 4. Aliases for route-level guards
        $middleware->alias([
            'tenant' => IdentifyTenantScope::class,
            'active' => EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Enforce JSON responses for API routes and JSON-accepting clients
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // 422 Validation Errors
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $details = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $msg) {
                        $details[] = [
                            'field' => $field,
                            'message' => $msg,
                        ];
                    }
                }

                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_FAILED',
                        'message' => 'Переданные данные не прошли валидацию.',
                        'details' => $details,
                        'trace_id' => $request->attributes->get('trace_id') ?: 'req-system',
                    ],
                ], 422);
            }
        });

        // 401 Unauthenticated
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHORIZED',
                        'message' => 'Требуется авторизация.',
                        'details' => [],
                        'trace_id' => $request->attributes->get('trace_id') ?: 'req-system',
                    ],
                ], 401);
            }
        });

        // 419 CSRF Token Mismatch
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'CSRF_TOKEN_MISMATCH',
                        'message' => 'Срок действия CSRF-токена истёк.',
                        'details' => [],
                        'trace_id' => $request->attributes->get('trace_id') ?: 'req-system',
                    ],
                ], 419);
            }
        });

        // Standard HTTP Exceptions (403, 404, 405, 409, 429)
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $statusCode = $e->getStatusCode();

                $codeMap = [
                    401 => 'UNAUTHORIZED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    405 => 'METHOD_NOT_ALLOWED',
                    409 => 'CONFLICT',
                    419 => 'CSRF_TOKEN_MISMATCH',
                    422 => 'UNPROCESSABLE_ENTITY',
                    429 => 'TOO_MANY_REQUESTS',
                ];

                $code = $codeMap[$statusCode] ?? ('HTTP_'.$statusCode);
                $message = $e->getMessage() ?: match ($statusCode) {
                    401 => 'Требуется авторизация.',
                    403 => 'Доступ запрещен.',
                    404 => 'Запрашиваемый ресурс не найден.',
                    default => 'Ошибка обработки HTTP-запроса.',
                };

                return response()->json([
                    'error' => [
                        'code' => $code,
                        'message' => $message,
                        'details' => [],
                        'trace_id' => $request->attributes->get('trace_id') ?: 'req-system',
                    ],
                ], $statusCode);
            }
        });

        // Direct HTTP Response Exceptions (e.g. RateLimiter custom response, abort())
        $exceptions->render(function (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        });

        // 500 Uncaught / Internal Server Error (never leak stack trace or internal paths)
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                Log::error('Unhandled server exception', [
                    'trace_id' => $request->attributes->get('trace_id'),
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'error' => [
                        'code' => 'INTERNAL_SERVER_ERROR',
                        'message' => 'Внутренняя ошибка сервера. Обратитесь к администратору с указанием trace_id.',
                        'details' => [],
                        'trace_id' => $request->attributes->get('trace_id') ?: 'req-system',
                    ],
                ], 500);
            }
        });
    })->create();
