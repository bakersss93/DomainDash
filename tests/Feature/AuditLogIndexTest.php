<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('Administrator');

        return $user;
    }

    public function test_audit_page_builds_filter_options_in_the_database(): void
    {
        foreach ([['domain.sync', 'synergy'], ['domain.sync', 'synergy'], ['ticket.create', 'halo'], [null, null]] as [$function, $service]) {
            AuditLog::create([
                'action' => 'test.action',
                'auditable_type' => 'system',
                'auditable_id' => 0,
                'description' => 'Test entry',
                'context' => array_filter(['function' => $function, 'service' => $service]),
            ]);
        }

        $response = $this->actingAs($this->admin())->get(route('admin.audit.index'));

        $response->assertOk();
        $this->assertSame(['domain.sync', 'ticket.create'], $response->viewData('functionOptions')->all());
        $this->assertSame(['halo', 'synergy'], $response->viewData('serviceOptions')->all());
        $this->assertGreaterThan(0, $response->viewData('dbBytes'));
    }
}
