<?php
 namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class UserRegistrationTest extends TestCase
{
    use RefreshDatabase; // ⚠️ WARNING: This will wipe the database during test

    // QUARANTINED: relies on RefreshDatabase + factories, but this app has no
    // migrations. Skipped (without booting the RefreshDatabase trait) until a
    // dedicated test database exists, so it can never wipe the shared isc DB.
    protected function setUp(): void
    {
        $this->markTestSkipped('Quarantined: needs migrations / a dedicated test DB.');
    }

    /** @test */
    public function it_registers_a_user_successfully()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Abdallah Al Sawaqi',
            'username' => 'abdallah',
            'email' => 'abdallah@example.com',
            'password' => 'securePass123',
            'password_confirmation' => 'securePass123',
        ]);

        $response->assertStatus(200); // or 200 depending on your controller
        $this->assertDatabaseHas('Secx_User_Master_T', [
            'email' => 'abdallah@example.com',
            'username' => 'abdallah',
        ]);
    }

    /** @test */
    public function it_requires_unique_email_and_username()
    {
        User::factory()->create([
            'email' => 'taken@example.com',
            'username' => 'takenuser',
        ]);

        $response = $this->postJson('/api/register', [
            'name' => 'Another User',
            'username' => 'takenuser',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'username']);
    }
}

