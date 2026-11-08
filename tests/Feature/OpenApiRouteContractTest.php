<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiRouteContractTest extends TestCase
{
    public function test_all_production_api_routes_match_openapi_specification_exactly(): void
    {
        $openapiPath = base_path('docs/openapi.yaml');
        $this->assertFileExists($openapiPath);

        $openapiContent = file_get_contents($openapiPath);
        $this->assertNotEmpty($openapiContent);

        // Extract active paths and methods from openapi.yaml
        $pathsSection = '';
        if (preg_match('/^paths:\s*\n(.*?)(?=\n(?:components|x-future-endpoints):)/sm', $openapiContent, $matches)) {
            $pathsSection = $matches[1];
        }

        $this->assertNotEmpty($pathsSection, 'OpenAPI paths section must not be empty.');

        $openapiOps = [];
        $currentPath = null;
        $lines = explode("\n", $pathsSection);
        foreach ($lines as $line) {
            if (preg_match('/^  (\/[^:]+):\s*$/', $line, $m)) {
                $currentPath = $m[1];
            } elseif ($currentPath !== null && preg_match('/^    (get|post|patch|put|delete):\s*$/', $line, $m)) {
                $openapiOps[] = strtoupper($m[1]).' '.$currentPath;
            }
        }

        sort($openapiOps);

        // Extract registered production Laravel routes
        $laravelOps = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }
            if (str_starts_with($uri, 'api/v1/test')) {
                continue; // Skip testing harness routes
            }

            $path = substr($uri, strlen('api/v1'));
            if ($path === '') {
                $path = '/';
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                    $laravelOps[] = $method.' '.$path;
                }
            }
        }

        $laravelOps = array_values(array_unique($laravelOps));
        sort($laravelOps);

        $missingInOpenapi = array_diff($laravelOps, $openapiOps);
        $missingInLaravel = array_diff($openapiOps, $laravelOps);

        $this->assertEmpty(
            $missingInOpenapi,
            'Laravel routes missing from OpenAPI: '.implode(', ', $missingInOpenapi)
        );

        $this->assertEmpty(
            $missingInLaravel,
            'OpenAPI operations missing from Laravel routes: '.implode(', ', $missingInLaravel)
        );

        $this->assertCount(59, $laravelOps, 'Expected exactly 59 active production API operations.');
        $this->assertSame($laravelOps, $openapiOps);
    }

    public function test_future_endpoints_are_isolated_in_future_section(): void
    {
        $openapiContent = file_get_contents(base_path('docs/openapi.yaml'));
        $this->assertStringContainsString('x-future-endpoints:', $openapiContent);
        $this->assertStringContainsString('/auth/forgot-password', $openapiContent);
    }

    public function test_openapi_does_not_contain_corrupted_empty_keys_or_broken_refs(): void
    {
        $openapiContent = file_get_contents(base_path('docs/openapi.yaml'));
        $this->assertDoesNotMatchRegularExpression('/^\s*\?\s*\'\'/m', $openapiContent, 'OpenAPI must not contain empty YAML keys (? \'\') replacing $ref.');
        $this->assertDoesNotMatchRegularExpression('/^\s*\'\':\s*[\'"]?#\//m', $openapiContent, 'OpenAPI must not contain empty key mapped to a reference.');

        // Ensure /scenario-requests/{id} specifically has valid $ref
        $this->assertStringContainsString('$ref: \'#/components/schemas/ScenarioRequestEnvelope\'', $openapiContent);
        $this->assertStringContainsString('$ref: \'#/components/responses/Unauthorized\'', $openapiContent);
        $this->assertStringContainsString('$ref: \'#/components/responses/Forbidden\'', $openapiContent);
        $this->assertStringContainsString('$ref: \'#/components/responses/NotFound\'', $openapiContent);
    }
}
