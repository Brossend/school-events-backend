<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Llm\LiteLlmProvider;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\LlmRuntime;
use App\Services\Llm\MockLlmProvider;
use App\Services\Llm\VariantLlmProviderInterface;
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
            return new TenantContext;
        });

        $this->app->bind(LlmProviderInterface::class, function ($app) {
            $metadata = LlmRuntime::metadata();

            return $app->make($metadata['provider_name'] === 'mock'
                ? MockLlmProvider::class : LiteLlmProvider::class);
        });
        $this->app->bind(VariantLlmProviderInterface::class,
            fn ($app) => $app->make(LlmProviderInterface::class));
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

        // 3. Fail-closed security guard for testing contour HTTP requests
        if ($this->app->environment('testing') && ! $this->app->runningInConsole()) {
            if (! file_exists(base_path('.env.testing'))) {
                abort(500, 'Security refusal: .env.testing is missing. Test contour cannot run on main .env configuration.');
            }
            $configuredDb = config('database.connections.pgsql.database');
            if ($configuredDb !== 'school_events_test') {
                abort(500, 'Security refusal: Test contour database must be school_events_test.');
            }
            $dbPassword = config('database.connections.pgsql.password');
            if (empty($dbPassword)) {
                abort(500, 'Security refusal: Test database password is not configured.');
            }
        }
    }
}
