<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Domain;
use App\Models\User;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DnsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;
    private Client $clientB;
    private Domain $domainA;
    private Domain $domainB;
    private User $customerA;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Administrator', 'Technician', 'Customer'] as $role) {
            Role::findOrCreate($role);
        }
        Permission::findOrCreate('dns.manage');

        $this->clientA = Client::create(['business_name' => 'Client A']);
        $this->clientB = Client::create(['business_name' => 'Client B']);
        $this->domainA = Domain::create(['name' => 'a.example.com', 'client_id' => $this->clientA->id]);
        $this->domainB = Domain::create(['name' => 'b.example.com', 'client_id' => $this->clientB->id]);

        $this->customerA = User::factory()->create();
        $this->customerA->assignRole('Customer');
        $this->customerA->givePermissionTo('dns.manage');
        $this->customerA->clients()->attach($this->clientA->id);
    }

    public function test_customer_cannot_read_another_clients_dns(): void
    {
        $this->actingAs($this->customerA)
            ->get(route('dns.index', $this->domainB))
            ->assertForbidden();
    }

    public function test_customer_cannot_modify_another_clients_dns_even_with_dns_manage(): void
    {
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldNotReceive('addDNSRecord', 'updateDNSRecord', 'deleteDNSRecord', 'updateNameServers');
        });

        $record = ['host' => 'www', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 300];

        $this->actingAs($this->customerA)->post(route('dns.store', $this->domainB), $record)->assertForbidden();
        $this->actingAs($this->customerA)->put(route('dns.update', [$this->domainB, 'r1']), $record)->assertForbidden();
        $this->actingAs($this->customerA)->delete(route('dns.destroy', [$this->domainB, 'r1']))->assertForbidden();
        $this->actingAs($this->customerA)->post(route('dns.options', $this->domainB), ['dns_mode' => 4])->assertForbidden();
    }

    public function test_user_with_no_client_link_is_denied(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('dns.index', $this->domainA))->assertForbidden();
    }

    public function test_customer_can_manage_own_clients_dns(): void
    {
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldReceive('addDNSRecord')->once();
        });

        $this->actingAs($this->customerA)
            ->post(route('dns.store', $this->domainA), ['host' => 'www', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 300])
            ->assertRedirect();
    }

    public function test_customer_without_dns_manage_cannot_write_own_domain(): void
    {
        $this->customerA->revokePermissionTo('dns.manage');

        $this->actingAs($this->customerA)
            ->post(route('dns.store', $this->domainA), ['host' => 'www', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 300])
            ->assertForbidden();
    }
}
