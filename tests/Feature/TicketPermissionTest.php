<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function customer(bool $withPermission): User
    {
        $u = User::factory()->create();
        $u->assignRole('Customer');
        if (! $withPermission) {
            $u->getAllPermissions(); // warm
            Role::findByName('Customer')->revokePermissionTo('tickets.create');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $u = $u->fresh();
        }

        return $u;
    }

    public function test_customer_role_has_tickets_create_by_default(): void
    {
        $this->assertTrue(Role::findByName('Customer')->hasPermissionTo('tickets.create'));
    }

    public function test_write_routes_are_forbidden_without_the_permission(): void
    {
        $u = $this->customer(false);

        $this->actingAs($u)->get('/tickets/create')->assertForbidden();
        $this->actingAs($u)->post('/tickets', [])->assertForbidden();
        $this->actingAs($u)->post('/tickets/5/reply', ['message' => 'x'])->assertForbidden();
        $this->actingAs($u)->post('/tickets/5/close', [])->assertForbidden();
    }

    public function test_viewing_own_tickets_stays_allowed_without_the_permission(): void
    {
        $u = $this->customer(false);

        $this->assertNotSame(403, $this->actingAs($u)->get('/tickets')->getStatusCode());
    }

    public function test_create_form_is_reachable_with_the_permission(): void
    {
        $this->actingAs($this->customer(true))->get('/tickets/create')->assertOk();
    }

    public function test_nav_hides_log_ticket_link_without_the_permission(): void
    {
        $with = $this->actingAs($this->customer(true))->get('/dashboard')->getContent();
        $this->assertStringContainsString('Log Support Ticket', $with);

        $without = $this->actingAs($this->customer(false))->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('Log Support Ticket', $without);
        $this->assertStringContainsString('View Support Requests', $without);
    }

    public function test_migration_grants_customer_and_is_idempotent(): void
    {
        Role::findByName('Customer')->revokePermissionTo('tickets.create');
        Permission::where('name', 'tickets.create')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_12_08_000001_grant_tickets_create_to_customer.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Role::findByName('Customer')->hasPermissionTo('tickets.create'));
        $this->assertTrue(Role::findByName('Technician')->hasPermissionTo('tickets.create'));
        $this->assertSame(1, Permission::where('name', 'tickets.create')->count());
    }

    public function test_ticket_help_articles_follow_tickets_create_and_partner_article_is_staff_only(): void
    {
        $repo = new \App\Support\Help\HelpRepository(resource_path('help'));

        $with = $this->customer(true);
        $this->assertNotNull($repo->find($with, 'support', 'raising-a-ticket'));
        $this->assertNotNull($repo->find($with, 'support', 'replying-to-tickets'));
        $this->assertNull($repo->find($with, 'integrations', 'microsoft-partner-connection'));

        $without = $this->customer(false);
        $this->assertNull($repo->find($without, 'support', 'raising-a-ticket'));
        $this->assertNull($repo->find($without, 'support', 'replying-to-tickets'));

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $this->assertNotNull($repo->find($admin, 'integrations', 'microsoft-partner-connection'));
    }
}
