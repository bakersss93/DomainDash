<?php

namespace Tests\Feature\Security;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class XssOutputEncodingTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_list_encodes_the_name_inside_the_whois_onclick_handler(): void
    {
        $this->withoutVite();
        Role::findOrCreate('Administrator', 'web');
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');
        $admin->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('domains.view', 'web'));
        Domain::create(['name' => "x');alert(document.cookie);//.example.com"]);

        $html = $this->actingAs($admin)->get('/admin/domains')->assertOk()->getContent();

        $this->assertStringContainsString('syncDomainWhois(', $html);
        // A raw single quote would end the JS string; @js emits a JSON string with escaped quotes instead.
        $this->assertDoesNotMatchRegularExpression("/syncDomainWhois\(\d+, '[^']*'\);alert/", $html);
        $this->assertStringNotContainsString("');alert(document.cookie)", $html);
    }

    /** Server / API supplied text must go through ddEsc() before being concatenated into innerHTML. */
    public function test_client_side_templates_escape_server_supplied_text(): void
    {
        $must = [
            'resources/views/admin/settings/partials/sync-scripts.blade.php' => '${ddEsc(item.client',
            'resources/views/admin/clients/index.blade.php' => ['${ddEsc(d.label)}', '${ddEsc(d.message)}'],
            'resources/views/admin/domains/purchase.blade.php' => '${ddEsc(data.message',
            'resources/views/admin/domains/transfer.blade.php' => '${ddEsc(data.message)}',
        ];
        foreach ($must as $file => $needles) {
            $src = file_get_contents(base_path($file));
            foreach ((array) $needles as $n) {
                $this->assertStringContainsString($n, $src, "$file must contain $n");
            }
        }

        $forbidden = [
            'resources/views/admin/settings/partials/sync-scripts.blade.php' => '${item.client',
            'resources/views/admin/clients/index.blade.php' => ['${d.label}', '${d.message}'],
            'resources/views/admin/domains/purchase.blade.php' => '<p>${data.message',
            'resources/views/admin/domains/transfer.blade.php' => '600;">${data.message}',
        ];
        foreach ($forbidden as $file => $needles) {
            $src = file_get_contents(base_path($file));
            foreach ((array) $needles as $n) {
                $this->assertStringNotContainsString($n, $src, "$file must not contain raw $n");
            }
        }

        $layout = file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $this->assertStringContainsString('window.ddEsc', $layout);
    }

    public function test_https_page_uses_js_encoding_for_alpine_state(): void
    {
        $src = file_get_contents(base_path('resources/views/admin/settings/https.blade.php'));
        $this->assertStringContainsString('@js(old(\'mode\'', $src);
        $this->assertStringNotContainsString("mode: '{{", $src);
    }
}
