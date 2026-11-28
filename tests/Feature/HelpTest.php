<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpRenderer;
use App\Support\Help\HelpRepository;
use App\Support\Security\StaffPermissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Support > Help knowledge base: access control, search, safe rendering and the real article set. */
class HelpTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_TITLE = 'Secret integration setup';

    protected function setUp(): void
    {
        parent::setUp();

        config(['help.path' => base_path('tests/Fixtures/help')]);
        $this->seed(RolePermissionSeeder::class);
    }

    private function customer(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Customer');

        return $u;
    }

    private function technician(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Technician');

        return $u;
    }

    private function admin(): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole('Administrator');

        return $u;
    }

    // ---- guests and customers ------------------------------------------------------------------------------

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/support/help')->assertRedirect('/login');
        $this->get('/support/help/general/hello-world')->assertRedirect('/login');
    }

    public function test_customer_sees_client_articles_but_never_integration_articles(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->get('/support/help')
            ->assertOk()
            ->assertSee('Hello world guide')
            ->assertDontSee(self::SECRET_TITLE)
            ->assertDontSee('Integration set-up (staff)');

        $this->actingAs($customer)->get('/support/help/general/hello-world')->assertOk();
    }

    public function test_customer_cannot_open_integration_article_or_category(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->get('/support/help/setup/secret-setup')->assertNotFound();
        $this->actingAs($customer)->get('/support/help/setup')->assertNotFound();
    }

    public function test_integration_articles_are_absent_from_customer_search_results(): void
    {
        $customer = $this->customer();

        // Title, summary and body words all miss for a customer.
        foreach (['ZEBRASECRET', 'ZEBRASUMMARY', 'Secret integration'] as $term) {
            $this->actingAs($customer)->get('/support/help?q=' . urlencode($term))
                ->assertOk()
                ->assertSee('0 results')
                ->assertDontSee(self::SECRET_TITLE)
                ->assertDontSee('/support/help/setup/secret-setup', false);
        }
    }

    public function test_customer_cannot_be_granted_the_integration_permission_through_the_permissions_page(): void
    {
        $this->assertContains('help.integrations.view', StaffPermissions::STAFF_ONLY);

        $role = Role::findByName('Customer');
        $this->assertFalse($role->hasPermissionTo('help.integrations.view'));

        $this->actingAs($this->admin())
            ->put(route('admin.permissions.roles.update', $role), ['permissions' => ['domains.view', 'help.integrations.view']])
            ->assertSessionHasErrors('permissions');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Role::findByName('Customer')->hasPermissionTo('help.integrations.view'));
    }

    public function test_a_customer_with_the_permission_given_directly_still_cannot_read_staff_articles(): void
    {
        // Defence in depth: the article gate also requires a staff role.
        $customer = $this->customer();
        $customer->givePermissionTo('help.integrations.view');

        $this->actingAs($customer)->get('/support/help/setup/secret-setup')->assertNotFound();
    }

    // ---- staff ---------------------------------------------------------------------------------------------

    public function test_technician_sees_integration_articles_by_default(): void
    {
        $tech = $this->technician();

        $this->actingAs($tech)->get('/support/help')->assertOk()->assertSee(self::SECRET_TITLE)->assertSee('Integration set-up (staff)');
        $this->actingAs($tech)->get('/support/help/setup/secret-setup')->assertOk()->assertSee('ZEBRASECRET');
        $this->actingAs($tech)->get('/support/help?q=ZEBRASECRET')->assertOk()->assertSee(self::SECRET_TITLE)->assertSee('1 result');
        $this->actingAs($tech)->get('/support/help/setup')->assertOk();
    }

    public function test_revoking_the_permission_from_technician_hides_integration_articles(): void
    {
        Role::findByName('Technician')->revokePermissionTo('help.integrations.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tech = $this->technician();

        $this->actingAs($tech)->get('/support/help')->assertOk()->assertDontSee(self::SECRET_TITLE);
        $this->actingAs($tech)->get('/support/help/setup/secret-setup')->assertNotFound();
        $this->actingAs($tech)->get('/support/help?q=ZEBRASECRET')->assertOk()->assertDontSee(self::SECRET_TITLE)->assertSee('0 results');
        // Client how-tos stay visible to staff.
        $this->actingAs($tech)->get('/support/help/general/hello-world')->assertOk();
    }

    public function test_admin_sees_everything(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/support/help')->assertOk()
            ->assertSee(self::SECRET_TITLE)->assertSee('Pineapple DNS tagged guide')->assertSee('Hello world guide');
        $this->actingAs($admin)->get('/support/help/setup/secret-setup')->assertOk();
        $this->actingAs($admin)->get('/support/help/general/dns-tagged')->assertOk();
    }

    // ---- permission-tagged client articles -----------------------------------------------------------------

    public function test_permission_tagged_client_article_is_hidden_without_the_permission(): void
    {
        $customer = $this->customer();
        $this->assertFalse($customer->can('dns.manage'));

        $this->actingAs($customer)->get('/support/help')->assertOk()->assertDontSee('Pineapple DNS tagged guide');
        $this->actingAs($customer)->get('/support/help/general/dns-tagged')->assertNotFound();
        $this->actingAs($customer)->get('/support/help?q=QUINCEWORD')->assertOk()->assertSee('0 results')->assertDontSee('Pineapple DNS tagged guide');
        $this->actingAs($customer)->get('/support/help/general')->assertOk()->assertDontSee('Pineapple DNS tagged guide');
    }

    public function test_permission_tagged_client_article_appears_once_the_permission_is_granted(): void
    {
        $customer = $this->customer();
        $customer->givePermissionTo('dns.manage');

        $this->actingAs($customer)->get('/support/help')->assertSee('Pineapple DNS tagged guide');
        $this->actingAs($customer)->get('/support/help/general/dns-tagged')->assertOk();
        $this->actingAs($customer)->get('/support/help?q=QUINCEWORD')->assertSee('Pineapple DNS tagged guide');
    }

    public function test_staff_see_permission_tagged_client_articles(): void
    {
        Role::findByName('Technician')->revokePermissionTo('dns.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->technician())->get('/support/help/general/dns-tagged')->assertOk();
    }

    // ---- rendering safety ----------------------------------------------------------------------------------

    public function test_markdown_html_is_escaped_and_unsafe_links_are_dropped(): void
    {
        $response = $this->actingAs($this->customer())->get('/support/help/general/xss-article')->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString("<script>alert('xss-one')", $html);
        $this->assertStringContainsString('&lt;script&gt;alert(', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('&lt;iframe', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('href="data:', $html);
        $this->assertStringContainsString('href="https://example.com/ok"', $html);
    }

    public function test_renderer_escapes_html_even_when_called_directly(): void
    {
        $article = new HelpArticle('client', 'x', 'y', 'T', '', 1, null, [], "<script>alert(1)</script>\n\n[a](javascript:alert(1))");
        $html = (new HelpRenderer())->render($article);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_placeholders_are_filled_and_other_braces_are_left_alone(): void
    {
        config(['app.name' => 'Contoso Portal', 'app.url' => 'https://portal.example.com/']);

        $html = $this->actingAs($this->customer())->get('/support/help/general/hello-world')->assertOk()->getContent();

        $this->assertStringContainsString('https://portal.example.com.', $html);
        $this->assertStringContainsString('Ask Contoso Portal for help.', $html);
        $this->assertStringContainsString('{plan} and {client_code}', $html);
        $this->assertStringNotContainsString('{company}', $html);
        $this->assertStringContainsString('help-callout help-callout--tip', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function test_branding_company_name_setting_is_used_when_present(): void
    {
        Setting::put('branding', ['company_name' => 'Fabrikam IT']);

        $html = $this->actingAs($this->customer())->get('/support/help/general/hello-world')->getContent();

        $this->assertStringContainsString('Ask Fabrikam IT for help.', $html);
    }

    // ---- path traversal ------------------------------------------------------------------------------------

    public function test_path_traversal_in_category_or_slug_is_rejected(): void
    {
        $admin = $this->admin();

        foreach ([
            '/support/help/..%2F..%2F..%2F..%2Fetc/passwd',
            '/support/help/general/..%2F..%2F..%2F.env',
            '/support/help/general/%2e%2e%2f%2e%2e%2f.env',
            '/support/help/general/hello-world.md',
            '/support/help/general/hello.world',
            '/support/help/general/HELLO-WORLD',
            '/support/help/..',
            '/support/help/client/general/hello-world', // audience is not part of the URL
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertNotFound();
        }
    }

    public function test_repository_never_resolves_traversal_input(): void
    {
        $repo = new HelpRepository(base_path('tests/Fixtures/help'));
        $admin = $this->admin();

        $this->assertNull($repo->find($admin, '../..', 'etc-passwd'));
        $this->assertNull($repo->find($admin, 'general', '../../../.env'));
        $this->assertNull($repo->find($admin, 'general', 'hello-world/../../x'));
        $this->assertNotNull($repo->find($admin, 'general', 'hello-world'));
    }

    // ---- navigation, permission list, migration ------------------------------------------------------------

    public function test_help_link_is_in_the_support_menu(): void
    {
        $this->actingAs($this->customer())->get('/support/help')->assertSee(route('help.index'), false);
    }

    public function test_permission_appears_on_the_permissions_page_with_a_friendly_label(): void
    {
        $this->actingAs($this->admin())->get(route('admin.permissions'))
            ->assertOk()
            ->assertSee('help.integrations.view')
            ->assertSee('Help: integration set-up guides');
    }

    public function test_seeder_and_migration_grant_staff_roles_and_are_idempotent(): void
    {
        $this->assertTrue(Role::findByName('Administrator')->hasPermissionTo('help.integrations.view'));
        $this->assertTrue(Role::findByName('Technician')->hasPermissionTo('help.integrations.view'));
        $this->assertFalse(Role::findByName('Customer')->hasPermissionTo('help.integrations.view'));

        // Simulate an existing install: roles exist, permission does not.
        Permission::where('name', 'help.integrations.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require base_path('database/migrations/2026_12_07_000001_add_help_integrations_permission.php');
        $migration->up();
        $migration->up(); // second run must be a no-op

        $this->assertSame(1, Permission::where('name', 'help.integrations.view')->count());
        $this->assertTrue(Role::findByName('Administrator')->hasPermissionTo('help.integrations.view'));
        $this->assertTrue(Role::findByName('Technician')->hasPermissionTo('help.integrations.view'));
        $this->assertFalse(Role::findByName('Customer')->hasPermissionTo('help.integrations.view'));
    }

    public function test_index_is_cached_and_refreshes_when_a_file_changes(): void
    {
        $dir = sys_get_temp_dir() . '/help-cache-' . bin2hex(random_bytes(4));
        mkdir($dir . '/client/general', 0777, true);
        file_put_contents($dir . '/client/general/one.md', "---\ntitle: One\nsummary: s\norder: 1\n---\nbody");

        $repo = new HelpRepository($dir);
        $this->assertSame(['general/one'], array_keys($repo->all()));

        file_put_contents($dir . '/client/general/two.md', "---\ntitle: Two\nsummary: s\norder: 2\n---\nbody");
        $this->assertSame(['general/one', 'general/two'], array_keys((new HelpRepository($dir))->all()));

        // Unreadable or malformed files and bad names are ignored, not fatal.
        file_put_contents($dir . '/client/general/no-front-matter.md', 'just text');
        file_put_contents($dir . '/client/general/Bad_Name.md', "---\ntitle: Bad\n---\nx");
        file_put_contents($dir . '/client/general/bad-perm.md', "---\ntitle: P\npermission: x; DROP\n---\nx");
        $this->assertSame(['general/one', 'general/two'], array_keys((new HelpRepository($dir))->all()));

        array_map('unlink', glob($dir . '/client/general/*.md'));
        rmdir($dir . '/client/general');
        rmdir($dir . '/client');
        rmdir($dir);
    }

    // ---- the real article set ------------------------------------------------------------------------------

    public function test_real_articles_are_well_formed_and_consistent(): void
    {
        config(['help.path' => resource_path('help')]);
        $repo = new HelpRepository(resource_path('help'));
        $articles = $repo->all();

        $this->assertGreaterThanOrEqual(30, count($articles));

        $seeded = (new \ReflectionClass(RolePermissionSeeder::class))->getFileName();
        $seederText = file_get_contents($seeded);
        $audiences = ['client' => 0, 'integration' => 0];
        $titles = [];

        foreach ($articles as $key => $a) {
            $file = resource_path("help/{$a->audience}/{$a->category}/{$a->slug}.md");
            $this->assertFileExists($file);
            $raw = file_get_contents($file);

            // Front matter matches the folder it lives in.
            $this->assertMatchesRegularExpression('/^category:\s*' . preg_quote($a->category, '/') . '\s*$/m', $raw, $key);
            $this->assertMatchesRegularExpression('/^audience:\s*' . preg_quote($a->audience, '/') . '\s*$/m', $raw, $key);
            $this->assertNotSame('', $a->summary, $key);
            $this->assertNotContains($a->title, $titles, "duplicate title in $key");
            $titles[] = $a->title;
            $audiences[$a->audience]++;

            // Tags are real permissions the app knows about.
            if ($a->permission !== null) {
                $this->assertStringContainsString("'{$a->permission}'", $seederText, "$key names unknown permission {$a->permission}");
            }
            // Hostile markup can't sneak in through a careless edit.
            $html = (new HelpRenderer())->render($a);
            $this->assertStringNotContainsString('<script', $html, $key);
            $this->assertStringNotContainsString('{company}', $html, $key);
            $this->assertStringNotContainsString('{app_url}', $html, $key);
        }

        $this->assertGreaterThanOrEqual(15, $audiences['client']);
        $this->assertGreaterThanOrEqual(15, $audiences['integration']);
    }

    public function test_real_article_links_point_at_real_articles(): void
    {
        $repo = new HelpRepository(resource_path('help'));
        $articles = $repo->all();

        foreach ($articles as $key => $a) {
            preg_match_all('#\]\((/support/help/([a-z0-9-]+)/([a-z0-9-]+))\)#', $a->body, $m, PREG_SET_ORDER);
            foreach ($m as $link) {
                $this->assertArrayHasKey($link[2] . '/' . $link[3], $articles, "$key links to missing article {$link[1]}");
            }
            foreach ($a->related as $slug) {
                $this->assertNotEmpty(array_filter($articles, fn ($x) => $x->slug === $slug), "$key relates to missing article $slug");
            }
            preg_match_all('#\]\((/[^)\s]*)\)#', $a->body, $others);
            foreach ($others[1] as $href) {
                $this->assertTrue(str_starts_with($href, '/support/help/') || $href === '/tickets/create', "$key has unexpected internal link $href");
            }
        }
    }

    public function test_every_integration_article_is_gated_and_every_real_article_renders_for_an_admin(): void
    {
        config(['help.path' => resource_path('help')]);
        $customer = $this->customer();
        $admin = $this->admin();

        foreach ((new HelpRepository(resource_path('help')))->all() as $a) {
            $url = route('help.show', [$a->category, $a->slug]);
            $this->actingAs($admin)->get($url)->assertOk();
            if ($a->isIntegration()) {
                $this->actingAs($customer)->get($url)->assertNotFound();
            }
        }
    }
}
