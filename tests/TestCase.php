<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected static bool $migrated = false;

    public function createApplication()
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        $app = parent::createApplication();
        $app['env'] = 'testing';

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Strict environment check
        if (! app()->environment('testing')) {
            throw new \RuntimeException("FATAL: Tests MUST only run in 'testing' environment! Current: '".app()->environment()."'");
        }

        // 2. Strict configured database check
        $configDb = config('database.connections.pgsql.database');
        if ($configDb !== 'school_events_test') {
            throw new \RuntimeException("FATAL: Configured database must be 'school_events_test'! Current config: '{$configDb}'");
        }

        // 3. Strict active PostgreSQL connection and database verification
        try {
            $result = DB::connection('pgsql')->selectOne('SELECT current_database() AS db');
            $activeDb = $result ? $result->db : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException("FATAL: Could not establish active PostgreSQL connection for tests: ".$e->getMessage(), 0, $e);
        }

        if ($activeDb === 'school_events' || $activeDb !== 'school_events_test') {
            throw new \RuntimeException("FATAL: Active PostgreSQL connection is connected to '{$activeDb}', but MUST be 'school_events_test'! Tests against primary database are forbidden.");
        }

        // 4. Ensure migrations have run on school_events_test for clean volumes from ZIP
        if (! static::$migrated) {
            Artisan::call('migrate', ['--force' => true]);
            static::$migrated = true;
        }

        // 5. Simulate Nuxt 4 SPA requests from stateful frontend domain
        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ]);
    }

    /**
     * Obtain fresh CSRF token and all cookies from Sanctum.
     *
     * @return array{token: ?string, cookies: array<string, string>}
     */
    protected function fetchCsrf(): array
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $cookies = [];
        $rawToken = null;

        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $rawToken = urldecode($cookie->getValue());
            }
        }

        return [
            'token' => $rawToken,
            'cookies' => $cookies,
        ];
    }

    /**
     * Send POST request with valid CSRF header and session cookies.
     */
    protected function postWithCsrf(string $uri, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $csrf = $this->fetchCsrf();

        return $this->withCookies($csrf['cookies'])
            ->withHeader('X-XSRF-TOKEN', $csrf['token'])
            ->postJson($uri, $data, $headers);
    }
}
