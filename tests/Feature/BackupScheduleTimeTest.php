<?php

namespace Tests\Feature;

use App\Console\ScheduleRegistrar;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackupScheduleTimeTest extends TestCase
{
    use RefreshDatabase;

    private function backupExpression(): string
    {
        $schedule = new Schedule();
        ScheduleRegistrar::register($schedule);

        return collect($schedule->events())->first(fn ($e) => $e->description === 'Command: run scheduled backup')->expression;
    }

    public function test_default_is_0300(): void
    {
        $this->assertSame('0 3 * * *', $this->backupExpression());
    }

    public function test_configured_time_is_used(): void
    {
        Setting::put('backup', ['time' => '22:45']);
        $this->assertSame('45 22 * * *', $this->backupExpression());
    }

    public function test_invalid_stored_time_falls_back_to_default(): void
    {
        foreach (['25:99', 'abc', '3:00', ''] as $bad) {
            Setting::put('backup', ['time' => $bad]);
            $this->assertSame('0 3 * * *', $this->backupExpression(), $bad);
        }
    }

    public function test_settings_form_validates_hh_mm(): void
    {
        Role::findOrCreate('Administrator', 'web');
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');

        $this->actingAs($admin)->post(route('admin.settings.update'), ['backup' => ['time' => '24:00']])->assertSessionHasErrors('backup.time');
        $this->actingAs($admin)->post(route('admin.settings.update'), ['backup' => ['time' => '04:15']])->assertSessionHasNoErrors();
        $this->assertSame('15 4 * * *', $this->backupExpression());
    }
}
