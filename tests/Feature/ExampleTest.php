<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_guests_are_redirected_to_login_from_the_root_route(): void
    {
        $response = $this->get('/');

        // '/' is behind the auth middleware (unchanged since the base branch): guests get a login redirect.
        $response->assertRedirect(route('login'));
    }
}
