<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Domain;
use App\Models\HostingService;
use App\Models\InternetService;
use App\Models\SslCertificate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The shared expandable-row action panel: <x-ui.row-detail>, <x-ui.action-grid>, <x-ui.action-tile>. */
class ActionTilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tile_renders_link_button_and_form_variants(): void
    {
        $link = Blade::render('<x-ui.action-tile href="/x" icon="eye" data-foo="bar">Overview</x-ui.action-tile>');
        $this->assertStringContainsString('<a href="/x"', $link);
        $this->assertStringContainsString('data-foo="bar"', $link);
        $this->assertStringContainsString('ui-action-tile__icon', $link);
        $this->assertStringNotContainsString('ui-action-tile--danger', $link);

        $button = Blade::render('<x-ui.action-tile onclick="doIt(1)" class="js-hook">Run</x-ui.action-tile>');
        $this->assertStringContainsString('<button type="button"', $button);
        $this->assertStringContainsString('onclick="doIt(1)"', $button);
        $this->assertStringContainsString('js-hook', $button);

        $form = Blade::render('<x-ui.action-tile action="/renew" confirm="Sure?" target="_blank">Renew</x-ui.action-tile>');
        $this->assertStringContainsString('<form method="POST" action="/renew"', $form);
        $this->assertStringContainsString('target="_blank"', $form);
        $this->assertStringContainsString('name="_token"', $form);
        $this->assertStringContainsString('onsubmit="return confirm(', $form);
    }

    public function test_tile_danger_and_disabled_variants(): void
    {
        $danger = Blade::render('<x-ui.action-tile href="/d" variant="danger">Delete</x-ui.action-tile>');
        $this->assertStringContainsString('ui-action-tile--danger', $danger);

        $disabled = Blade::render('<x-ui.action-tile href="/d" disabled>Soon</x-ui.action-tile>');
        $this->assertStringContainsString('is-disabled', $disabled);
        $this->assertStringContainsString('disabled', $disabled);
        $this->assertStringContainsString('aria-disabled="true"', $disabled);
        $this->assertStringNotContainsString('href=', $disabled);
    }

    public function test_row_detail_escapes_text_and_renders_summary(): void
    {
        $html = Blade::render('<table><x-ui.row-detail data-x-panel="1" :title="$t" meta="meta line" aside="right"><x-ui.action-grid>g</x-ui.action-grid></x-ui.row-detail></table>', ['t' => '<b>x</b>']);

        $this->assertStringContainsString('ui-row-panel', $html);
        $this->assertStringContainsString('data-x-panel="1"', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('ui-row-detail__aside', $html);
        $this->assertStringContainsString('ui-action-grid', $html);
    }

    public function test_domains_hosting_ssl_and_internet_lists_use_the_shared_panel(): void
    {
        Http::fake(fn (HttpRequest $r) => Http::response([], 200));
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');

        $client = Client::create(['business_name' => 'Tile Pty Ltd']);
        Domain::create(['name' => 'tiles.example.com', 'client_id' => $client->id, 'status' => 'active']);
        HostingService::forceCreate(['client_id' => $client->id, 'domain_name' => 'tiles.example.com', 'service_status' => 'ACTIVE']);
        SslCertificate::forceCreate(['client_id' => $client->id, 'common_name' => 'tiles.example.com']);
        InternetService::forceCreate(['vocus_service_id' => 'DD-TILE', 'client_id' => $client->id, 'service_status' => 'ACTIVE', 'service_type' => 'FTTC']);

        $domains = $this->actingAs($admin)->get('/admin/domains')->assertOk();
        $domains->assertSee('ui-row-panel', false);
        $domains->assertSee('ui-action-tile--danger', false);
        $domains->assertSee('Delete domain');
        $domains->assertSee('syncDomainWhois(', false);
        $domains->assertSee('data-assign-client=', false);
        $domains->assertDontSee('dd-domain-option', false);

        $hosting = $this->actingAs($admin)->get('/admin/services/hosting')->assertOk();
        $hosting->assertSee('ui-row-panel', false);
        $hosting->assertSee('ui-action-tile--danger', false);
        $hosting->assertSee('data-password-id=', false);
        $hosting->assertSee('dd-change-domain-btn', false);
        $hosting->assertSee('Open cPanel');
        $hosting->assertDontSee('dd-service-option', false);

        $this->actingAs($admin)->get('/admin/services/ssls')->assertOk()
            ->assertSee('ui-action-grid', false)->assertSee('data-open-rekey=', false);

        $this->actingAs($admin)->get('/admin/services/internet')->assertOk()
            ->assertSee('ui-action-grid', false)->assertSee('data-row-panel=', false);
    }
}
