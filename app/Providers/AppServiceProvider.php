<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped to HTTP request / queue job lifecycle
        $this->app->scoped(TenantContext::class, function () {
            return new TenantContext();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 1. Rate limiter for authentication: 5 attempts/min per email+IP, and independent 10 attempts/min per IP
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email', ''));
            $ip = $request->ip() ?: '127.0.0.1';

            $responseCallback = function (Request $request, array $headers) {
                return response()->json([
                    'error' => [
                        'code' => 'TOO_MANY_REQUESTS',
                        'message' => 'Слишком много попыток входа. Пожалуйста, повторите попытку через 1 минуту.',
                        'details' => [],
                        'trace_id' => $request->attributes->get('trace_id'),
                    ],
                ], 429, $headers);
            };

            return [
                Limit::perMinute(5)->by($email.'|'.$ip)->response($responseCallback),
                Limit::perMinute(10)->by('ip:'.$ip)->response($responseCallback),
            ];
        });

        // 2. Prevent TenantContext leakage across jobs in long-running queue workers
        Queue::looping(function () {
            if ($this->app->bound(TenantContext::class)) {
                $this->app->make(TenantContext::class)->clear();
            }
        });
    }
}
