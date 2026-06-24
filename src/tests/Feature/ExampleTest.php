<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Smoke test: the API boots and validation runs without touching the database.
     * (This is an API-only app, so "/" is intentionally 404.)
     */
    public function test_login_validation_runs_without_database(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
