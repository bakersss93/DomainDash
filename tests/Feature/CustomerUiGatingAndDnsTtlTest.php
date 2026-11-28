<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Domain;
use App\Models\User;
use App\Services\Synergy\SynergyWholesaleClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerUiGatingAndDnsTtlTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $client = Client::create(['business_name' => 'Acme Pty']);
        $this->domain = Domain::create(['name' => 'acme.example.com', 'client_id' => $client->id, 'expiry_date' => now()->addDays(100)]);
        $this->client = $client;
    }

    private Client $client;

    private function customer(bool $dnsManage): User
    {
        $u = User::factory()->create();
        $u->assignRole('Customer');
        $u->clients()->attach($this->client->id);
        if ($dnsManage) {
            Role::findByName('Customer')->givePermissionTo('dns.manage');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $u->fresh();
    }

    private function staff(): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole('Administrator');

        return $u;
    }

    private function fakeSynergy(): void
    {
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldReceive('getNameserversAndConfig')->andReturn(['dnsConfig' => 4, 'nameServers' => []]);
            $m->shouldReceive('listDNSZone')->andReturn([['id' => 'r1', 'hostName' => 'www', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 300]]);
        });
    }

    public function test_dns_page_is_read_only_without_dns_manage(): void
    {
        $this->fakeSynergy();
        $html = $this->actingAs($this->customer(false))->get(route('dns.index', $this->domain))->assertOk()->getContent();

        $this->assertStringContainsString('1.2.3.4', $html);
        $this->assertStringNotContainsString('Add record', $html);
        $this->assertStringNotContainsString('id="dd-dns-options-btn"', $html);
        $this->assertStringNotContainsString('Delete', $html);
        $this->assertStringNotContainsString('name="ttl"', $html);
        $this->assertStringNotContainsString('dns-update-r1', $html);
    }

    public function test_dns_page_shows_controls_with_dns_manage(): void
    {
        $this->fakeSynergy();
        $html = $this->actingAs($this->customer(true))->get(route('dns.index', $this->domain))->assertOk()->getContent();

        $this->assertStringContainsString('Add record', $html);
        $this->assertStringContainsString('id="dd-dns-options-btn"', $html);
        $this->assertStringContainsString('dns-update-r1', $html);
    }

    public function test_customer_domain_list_has_no_staff_tiles(): void
    {
        $html = $this->actingAs($this->customer(false))->get(route('admin.domains'))->assertOk()->getContent();

        $this->assertStringContainsString('acme.example.com', $html);
        $this->assertStringContainsString('Nameservers &amp; DNS', $html);
        foreach (['>Renew<', 'data-assign-client="', 'Initiate transfer', '>Transactions<', '>WHOIS sync<', 'Delete domain', 'Bulk domain sync', 'Password / auth code', 'id="dd-assign-modal"'] as $label) {
            $this->assertStringNotContainsString($label, $html, $label);
        }
    }

    public function test_staff_domain_list_keeps_the_tiles(): void
    {
        $html = $this->actingAs($this->staff())->get(route('admin.domains'))->assertOk()->getContent();

        foreach (['>Renew<', 'data-assign-client="', 'Initiate transfer', 'Bulk domain sync', 'Password / auth code'] as $label) {
            $this->assertStringContainsString($label, $html, $label);
        }
    }

    public function test_customer_domain_overview_hides_assignment_and_auth_code(): void
    {
        $html = $this->actingAs($this->customer(false))->get(route('admin.domains.show', $this->domain))->assertOk()->getContent();

        $this->assertStringNotContainsString('Update client', $html);
        $this->assertStringNotContainsString('Get auth code', $html);

        $staff = $this->actingAs($this->staff())->get(route('admin.domains.show', $this->domain))->getContent();
        $this->assertStringContainsString('Update client', $staff);
        $this->assertStringContainsString('Get auth code', $staff);
    }

    public function test_web_dns_ttl_is_limited_to_the_ui_range(): void
    {
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldReceive('addDNSRecord')->once();
        });
        $staff = $this->staff();
        $rec = ['host' => 'www', 'type' => 'A', 'content' => '1.1.1.1'];

        $this->actingAs($staff)->post(route('dns.store', $this->domain), $rec + ['ttl' => 86401])->assertSessionHasErrors('ttl');
        $this->actingAs($staff)->post(route('dns.store', $this->domain), $rec + ['ttl' => 604800])->assertSessionHasErrors('ttl');
        $this->actingAs($staff)->post(route('dns.store', $this->domain), $rec + ['ttl' => 59])->assertSessionHasErrors('ttl');
        $this->actingAs($staff)->post(route('dns.store', $this->domain), $rec + ['ttl' => 86400])->assertSessionHasNoErrors();
    }

    public function test_api_dns_ttl_is_limited_to_the_ui_range(): void
    {
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldReceive('addDNSRecord')->once();
        });
        $gen = ApiKey::generate();
        ApiKey::create(['name' => 't', 'key_prefix' => $gen['prefix'], 'key_hash' => $gen['hash'], 'rate_limit_per_hour' => 1000, 'scopes' => ['dns.write'], 'active' => true]);
        $h = ['x-api-key' => $gen['plain']];
        $rec = ['host' => 'www', 'type' => 'A', 'content' => '1.1.1.1'];
        $url = "/api/v1/domains/{$this->domain->id}/dns";

        $this->postJson($url, $rec + ['ttl' => 604800], $h)->assertStatus(422);
        $this->postJson($url, $rec + ['ttl' => 86401], $h)->assertStatus(422);
        $this->postJson($url, $rec + ['ttl' => 86400], $h)->assertStatus(201);
    }

    public function test_api_key_form_says_scopes_are_required(): void
    {
        $html = $this->actingAs($this->staff())->get(route('admin.apikeys'))->assertOk()->getContent();

        $this->assertStringNotContainsString('full access', $html);
        $this->assertStringContainsString('required — select at least one', $html);
    }
}
