<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $user = User::factory()->create();
        $user->assignRole('Administrator');

        return $user;
    }

    public function test_downloads_require_authentication(): void
    {
        $this->get('/api-docs/openapi.yaml')->assertRedirect('/login');
        $this->get('/api-docs/openapi.json')->assertRedirect('/login');
    }

    public function test_non_admin_cannot_download(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/api-docs/openapi.yaml')->assertForbidden();
        $this->get('/api-docs/openapi.json')->assertForbidden();
    }

    public function test_json_download_is_valid_openapi_with_server_url_substituted(): void
    {
        config(['app.url' => 'https://dd.example.test']);
        $this->actingAs($this->admin());

        $res = $this->get('/api-docs/openapi.json')->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));

        $doc = json_decode($res->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^3\.1\.\d+$/', $doc['openapi']);
        $this->assertSame('DomainDash API', $doc['info']['title']);
        $this->assertSame('https://dd.example.test', $doc['servers'][0]['variables']['baseUrl']['default']);
        $this->assertSame('x-api-key', $doc['components']['securitySchemes']['ApiKeyAuth']['name']);
    }

    public function test_yaml_download_is_valid_openapi_with_server_url_substituted(): void
    {
        config(['app.url' => 'https://dd.example.test']);
        $this->actingAs($this->admin());

        $res = $this->get('/api-docs/openapi.yaml')->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));

        $doc = Yaml::parse($res->getContent());
        $this->assertMatchesRegularExpression('/^3\.1\.\d+$/', (string) $doc['openapi']);
        $this->assertSame('https://dd.example.test', $doc['servers'][0]['variables']['baseUrl']['default']);
    }

    public function test_yaml_and_json_sources_are_identical(): void
    {
        $yaml = Yaml::parse(file_get_contents(resource_path('openapi/domaindash-openapi.yaml')));
        $json = json_decode(file_get_contents(resource_path('openapi/domaindash-openapi.json')), true);

        $this->assertEquals($json, $yaml);
    }

    public function test_every_api_route_is_documented_and_vice_versa(): void
    {
        $spec = json_decode(file_get_contents(resource_path('openapi/domaindash-openapi.json')), true);

        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertArrayHasKey('operationId', $operation, "$method $path lacks operationId");
                $documented[] = strtoupper($method) . ' ' . preg_replace('/\{[^}]+\}/', '{}', $path);
            }
        }

        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $actual[] = $method . ' ' . preg_replace('/\{[^}]+\}/', '{}', substr($route->uri(), strlen('api')));
            }
        }

        sort($documented);
        sort($actual);
        $this->assertNotEmpty($actual);
        $this->assertSame($actual, $documented);

        $ids = array_merge(...array_map(fn ($m) => array_column($m, 'operationId'), $spec['paths']));
        $this->assertSame(count($ids), count(array_unique($ids)), 'operationIds must be unique');
    }
}
