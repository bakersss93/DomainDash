<?php

namespace Tests\Feature;

use App\Casts\EncryptedSettingValue;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\SystemAlerts;
use App\Services\Sync\QueueHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['domaindash.notifications.cache_seconds' => 0]);
        EncryptedSettingValue::$decryptFailures = [];
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole($role);

        return $u;
    }

    private function healthyWorker(): void
    {
        config(['queue.default' => 'sync']);
    }

    private function keys(User $u): array
    {
        return array_column($this->actingAs($u)->getJson('/notifications/summary')->assertOk()->json('alerts'), 'key');
    }

    public function test_admin_summary_endpoint_is_admin_only(): void
    {
        $this->actingAs($this->user('Customer'))->getJson('/admin/notifications/summary')->assertForbidden();
        $this->actingAs($this->user('Administrator'))->getJson('/admin/notifications/summary')
            ->assertOk()->assertJsonStructure(['count', 'severity', 'alerts', 'in_progress', 'notifications']);
    }

    public function test_customer_sees_own_notifications_but_no_system_alerts(): void
    {
        config(['queue.default' => 'database']); // stale worker would alert an admin
        $customer = $this->user('Customer');
        $other = $this->user('Customer');
        UserNotification::create(['user_id' => $customer->id, 'type' => 't', 'title' => 'Mine', 'message' => 'm']);
        UserNotification::create(['user_id' => $other->id, 'type' => 't', 'title' => 'Theirs', 'message' => 'm']);

        $json = $this->actingAs($customer)->getJson('/notifications/summary')->assertOk()->json();

        $this->assertFalse($json['is_admin']);
        $this->assertSame([], $json['alerts']);
        $this->assertSame([], $json['in_progress']);
        $this->assertSame(['Mine'], array_column($json['notifications'], 'title'));
        $this->assertSame(1, $json['count']);
    }

    public function test_stale_heartbeat_generates_worker_alert_and_fresh_heartbeat_clears_it(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user('Administrator');

        $this->assertContains('queue-worker', $this->keys($admin));

        QueueHealth::stamp();
        $this->assertNotContains('queue-worker', $this->keys($admin));
    }

    public function test_config_problem_generates_alert(): void
    {
        $this->healthyWorker();
        $admin = $this->user('Administrator');
        Setting::put('halo', ['base_url' => 'https://halo.example.com']); // client id + key missing

        $this->assertContains('config:halo:incomplete', $this->keys($admin));

        EncryptedSettingValue::$decryptFailures['vocus'] = true;
        $alerts = $this->actingAs($admin)->getJson('/notifications/summary')->json('alerts');
        $this->assertContains('config:vocus:decrypt', array_column($alerts, 'key'));
        $this->assertSame('error', $alerts[0]['severity'], 'most severe alert is listed first');
    }

    public function test_failed_run_generates_alert_unless_a_later_run_succeeded(): void
    {
        $this->healthyWorker();
        $admin = $this->user('Administrator');
        $run = SyncRun::queue('halo', 'clients');
        $run->forceFill(['status' => SyncRun::FAILED, 'error' => 'boom'])->save();

        $this->assertContains('sync-failed:halo', $this->keys($admin));

        SyncRun::queue('halo', 'clients')->forceFill(['status' => SyncRun::OK])->save();
        $this->assertNotContains('sync-failed:halo', $this->keys($admin));
    }

    public function test_running_sync_is_reported_in_progress_with_percent(): void
    {
        $this->healthyWorker();
        $admin = $this->user('Administrator');
        SyncRun::queue('halo', 'domains')->forceFill(['status' => SyncRun::RUNNING, 'total' => 10, 'done' => 4])->save();

        $json = $this->actingAs($admin)->getJson('/notifications/summary')->json();

        $this->assertSame(1, $json['in_progress_count']);
        $this->assertSame(40, $json['in_progress'][0]['percent']);
    }

    public function test_dismiss_persists_and_alert_reappears_after_snooze_period(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user('Administrator');
        $this->assertContains('queue-worker', $this->keys($admin));

        $this->actingAs($admin)->postJson('/admin/notifications/alerts/snooze', ['key' => 'queue-worker', 'hours' => 1])->assertOk();
        $this->assertNotContains('queue-worker', $this->keys($admin));
        $this->assertDatabaseHas('notification_alert_states', ['user_id' => $admin->id, 'alert_key' => 'queue-worker']);

        // Other admins are unaffected: state is per user.
        $this->assertContains('queue-worker', $this->keys($this->user('Administrator')));

        $this->travel(61)->minutes();
        Cache::flush();
        $this->assertContains('queue-worker', $this->keys($admin));
    }

    public function test_snooze_state_is_forgotten_when_condition_clears(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user('Administrator');
        $this->actingAs($admin)->postJson('/admin/notifications/alerts/snooze', ['key' => 'queue-worker'])->assertOk();

        QueueHealth::stamp();
        $this->keys($admin);
        $this->assertDatabaseMissing('notification_alert_states', ['alert_key' => 'queue-worker']);

        Cache::forget(QueueHealth::HEARTBEAT_KEY);
        $this->assertContains('queue-worker', $this->keys($admin));
    }

    public function test_invalid_snooze_hours_rejected_and_customers_cannot_snooze(): void
    {
        $this->actingAs($this->user('Administrator'))->postJson('/admin/notifications/alerts/snooze', ['key' => 'x', 'hours' => 5])->assertStatus(422);
        $this->actingAs($this->user('Customer'))->postJson('/admin/notifications/alerts/snooze', ['key' => 'x'])->assertForbidden();
    }

    public function test_counter_is_unread_notifications_plus_active_alerts_and_severity_follows_alerts(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user('Administrator');
        UserNotification::create(['user_id' => $admin->id, 'type' => 't', 'title' => 'A', 'message' => 'm']);
        UserNotification::create(['user_id' => $admin->id, 'type' => 't', 'title' => 'B', 'message' => 'm']);
        $read = UserNotification::create(['user_id' => $admin->id, 'type' => 't', 'title' => 'C', 'message' => 'm', 'read_at' => now()]);

        $json = $this->actingAs($admin)->getJson('/notifications/summary')->json();
        $this->assertSame(2, $json['unread_count']);
        $this->assertSame($json['alert_count'] + 2, $json['count']);
        $this->assertContains($json['severity'], ['warning', 'error']);

        $this->actingAs($admin)->postJson(route('notifications.read-all'))->assertOk();
        $this->assertSame(0, $this->actingAs($admin)->getJson('/notifications/summary')->json('unread_count'));
    }

    public function test_mark_read_via_json_only_for_owner(): void
    {
        $owner = $this->user('Customer');
        $n = UserNotification::create(['user_id' => $owner->id, 'type' => 't', 'title' => 'A', 'message' => 'm']);

        $this->actingAs($this->user('Customer'))->postJson(route('notifications.read', $n))->assertForbidden();
        $res = $this->actingAs($owner)->postJson(route('notifications.read', $n))->assertOk();
        $this->assertSame(0, $res->json('count'));
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_pages_render_bell_with_badge_and_no_full_width_worker_banner(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user('Administrator');

        $html = $this->actingAs($admin)->get('/admin/settings/halopsa')->assertOk()->getContent();

        $this->assertStringContainsString('data-notif-bell', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringNotContainsString('<span data-dd-worker-msg', $html);
        $this->assertStringContainsString('dd-worker-hint', $html);
    }

    public function test_alert_providers_are_listed_for_admins_only_via_service(): void
    {
        config(['queue.default' => 'database']);
        $svc = app(SystemAlerts::class);
        $this->assertNotEmpty($svc->forUser($this->user('Administrator')));
        $this->assertSame([], $svc->forUser($this->user('Customer')));
    }
}
