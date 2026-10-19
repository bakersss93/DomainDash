<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Smoke + safety tests for the shared Blade UI components (resources/views/components/ui).
 * Rendering goes through Blade::render so no database / auth is required.
 */
class UiComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_badge_maps_status_and_escapes_content(): void
    {
        $html = Blade::render('<x-ui.badge status="expired">{{ $label }}</x-ui.badge>', ['label' => '<script>alert(1)</script>']);

        $this->assertStringContainsString('ui-badge--danger', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_badge_without_slot_uses_status_label_and_explicit_variant_wins(): void
    {
        $this->assertStringContainsString('Suspended', Blade::render('<x-ui.badge status="suspended" />'));
        $this->assertStringContainsString('ui-badge--info', Blade::render('<x-ui.badge variant="info" status="expired">x</x-ui.badge>'));
    }

    public function test_button_renders_link_or_button(): void
    {
        $link = Blade::render('<x-ui.button href="/x" variant="secondary">Go</x-ui.button>');
        $this->assertStringContainsString('<a href="/x"', $link);
        $this->assertStringContainsString('ui-btn--secondary', $link);

        $button = Blade::render('<x-ui.button type="submit" variant="danger" size="sm">Del</x-ui.button>');
        $this->assertStringContainsString('<button type="submit"', $button);
        $this->assertStringContainsString('ui-btn--danger', $button);
        $this->assertStringContainsString('ui-btn--sm', $button);
    }

    public function test_icon_button_has_accessible_name(): void
    {
        $html = Blade::render('<x-ui.icon-button icon="trash" label="Delete user" variant="danger" type="submit" />');

        $this->assertStringContainsString('aria-label="Delete user"', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_field_ties_label_to_control_escapes_value_and_shows_error(): void
    {
        $html = Blade::render(
            '<x-ui.field label="Email" name="email" type="email" :value="$v" help="We never share it" error="Required" :required="true" />',
            ['v' => '"><script>x</script>']
        );

        $this->assertStringContainsString('<label for="field-email"', $html);
        $this->assertStringContainsString('id="field-email"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="field-email-help field-email-error"', $html);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('Required', $html);
    }

    public function test_field_never_echoes_password_values(): void
    {
        $html = Blade::render('<x-ui.field label="Password" name="password" type="password" value="secret123" />');

        $this->assertStringNotContainsString('secret123', $html);
    }

    public function test_field_select_and_textarea_variants(): void
    {
        $select = Blade::render('<x-ui.field label="Role" name="role" as="select"><option value="a">A</option></x-ui.field>');
        $this->assertStringContainsString('<select', $select);
        $this->assertStringContainsString('<option value="a">A</option>', $select);

        $textarea = Blade::render('<x-ui.field label="Notes" name="notes" as="textarea" value="hello" />');
        $this->assertStringContainsString('<textarea', $textarea);
        $this->assertStringContainsString('hello', $textarea);
    }

    public function test_alert_uses_alert_role_for_danger_and_status_for_success(): void
    {
        $this->assertStringContainsString('role="alert"', Blade::render('<x-ui.alert variant="danger">Bad</x-ui.alert>'));
        $this->assertStringContainsString('role="status"', Blade::render('<x-ui.alert variant="success">Ok</x-ui.alert>'));
    }

    public function test_card_page_header_stat_table_empty_state_and_modal_render(): void
    {
        $header = Blade::render('<x-ui.page-header title="Users" subtitle="Manage"><x-slot name="actions"><a href="/n">New</a></x-slot></x-ui.page-header>');
        $this->assertStringContainsString('<h1 class="ui-page-title">Users</h1>', $header);
        $this->assertStringContainsString('ui-page-actions', $header);

        $card = Blade::render('<x-ui.card title="T" :flush="true">Body<x-slot name="footer">F</x-slot></x-ui.card>');
        $this->assertStringContainsString('ui-card--flush', $card);
        $this->assertStringContainsString('ui-card__footer', $card);

        $this->assertStringContainsString('ui-stat__value', Blade::render('<x-ui.stat label="Domains" value="12" hint="2 expiring" variant="warning" />'));
        $this->assertStringContainsString('ui-table-wrap', Blade::render('<x-ui.table><tbody></tbody></x-ui.table>'));
        $this->assertStringContainsString('ui-empty__title', Blade::render('<x-ui.empty-state title="Nothing" />'));

        $modal = Blade::render('<x-ui.modal id="m1" title="Hello">Body</x-ui.modal>');
        $this->assertStringContainsString('id="m1"', $modal);
        $this->assertStringContainsString('aria-labelledby="m1-title"', $modal);
        $this->assertStringContainsString('role="dialog"', $modal);
    }

    public function test_login_page_renders_with_design_system_assets_and_logo(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('images/jargon-logo.png', false);
        $response->assertSee('prefers-color-scheme', false);
    }
}
