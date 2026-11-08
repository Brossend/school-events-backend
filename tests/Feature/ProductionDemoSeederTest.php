<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ProductionDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionDemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_repeated_bootstrap_preserves_credentials_and_unverified_evidence(): void
    {
        $originalEnvironment = $_ENV;
        $originalServer = $_SERVER;
        foreach (['ADMIN', 'TEACHER', 'DIRECTOR'] as $role) {
            $_ENV['DEMO_'.$role.'_PASSWORD'] = str_repeat('test-only-password-', 2);
            $_SERVER['DEMO_'.$role.'_PASSWORD'] = str_repeat('test-only-password-', 2);
        }

        try {
            $this->seed(ProductionDemoSeeder::class);
            $teacher = User::where('email', 'teacher.alpha@demo.school-events.ru')->firstOrFail();
            $teacher->update(['password' => Hash::make('changed-after-bootstrap')]);
            $this->seed(ProductionDemoSeeder::class);
        } finally {
            $_ENV = $originalEnvironment;
            $_SERVER = $originalServer;
        }

        $this->assertSame(1, User::where('email', $teacher->email)->count());
        $this->assertTrue(Hash::check('changed-after-bootstrap', $teacher->fresh()->password));
        $this->assertDatabaseHas('cultural_claim_sources', ['verification_status' => 'unverified', 'notes' => 'ДЕМО: проверка запрета обхода Provenance Gate.']);
    }

    public function test_bootstrap_refuses_live_provider_before_writing_data(): void
    {
        config(['llm.provider' => 'litellm']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Synthetic deployment data requires MockProvider.');

        $this->seed(ProductionDemoSeeder::class);
    }
}
