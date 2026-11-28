<?php

namespace Tests\Feature\Backup;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\Backup\RemoteStorageFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\FakeCredential;
use Tests\TestCase;

class BackupRunTest extends TestCase
{
    private string $encryptionKey = '';

    use RefreshDatabase;

    private string $remoteDir;
    private string $sqlite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remoteDir = sys_get_temp_dir() . '/dd-backup-remote-' . bin2hex(random_bytes(4));
        mkdir($this->remoteDir, 0700, true);

        $this->sqlite = sys_get_temp_dir() . '/dd-backup-src-' . bin2hex(random_bytes(4)) . '.sqlite';
        file_put_contents($this->sqlite, 'SQLITE-FAKE-CONTENT-' . str_repeat('x', 200));

        config(['database.connections.sqlite.database' => $this->sqlite]);

        $dir = $this->remoteDir;
        $this->app->bind(RemoteStorageFactory::class, fn () => new class($dir) extends RemoteStorageFactory {
            public function __construct(private string $dir) {}
            public function make(array $backup): FilesystemOperator
            {
                return new Filesystem(new LocalFilesystemAdapter($this->dir));
            }
        });

        Setting::put('backup', [
            'host' => 'backup.example.com', 'username' => 'u', 'password' => FakeCredential::secret('sftp'), 'path' => '/', 'retention' => 2,
            'host_fingerprint' => 'SHA256:abc', 'encryption_password' => $this->encryptionKey = FakeCredential::password(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->remoteDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->remoteDir);
        @unlink($this->sqlite);
        parent::tearDown();
    }

    private function localTemps(): array
    {
        return glob(storage_path('app/backups/tmp_*')) ?: [];
    }

    public function test_sqlite_backup_is_encrypted_uploaded_and_local_copies_removed(): void
    {
        $result = app(BackupService::class)->run();

        $remote = $this->remoteDir . '/' . $result['file'];
        $this->assertFileExists($remote);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($remote));
        // Without the password the content cannot be read.
        $this->assertFalse(@$zip->getFromName('database.sqlite'));
        $zip->setPassword($this->encryptionKey);
        $this->assertStringStartsWith('SQLITE-FAKE-CONTENT-', (string) $zip->getFromName('database.sqlite'));
        $stat = $zip->statName('database.sqlite');
        $this->assertSame(\ZipArchive::EM_AES_256, $stat['encryption_method']);
        $zip->close();

        $this->assertSame([], $this->localTemps(), 'local copies must be deleted');
    }

    public function test_success_is_audited_and_last_success_is_stored(): void
    {
        $this->assertNull(BackupService::status()['last_success_at']);

        $result = app(BackupService::class)->run();

        $status = BackupService::status();
        $this->assertNotNull($status['last_success_at']);
        $this->assertSame($result['file'], $status['last_file']);
        $this->assertTrue(AuditLog::where('action', 'backup.completed')->exists());
    }

    public function test_refused_without_fingerprint_or_encryption_password_and_failure_is_audited(): void
    {
        Setting::put('backup', array_merge((array) Setting::get('backup'), ['host_fingerprint' => '']));
        try {
            app(BackupService::class)->run();
            $this->fail('expected refusal');
        } catch (BackupException $e) {
            $this->assertStringContainsString('fingerprint', $e->getMessage());
        }
        $this->assertTrue(AuditLog::where('action', 'backup.failed')->exists());
        $this->assertNull(BackupService::status()['last_success_at']);
        $this->assertNotNull(BackupService::status()['last_error']);

        Setting::put('backup', array_merge((array) Setting::get('backup'), ['host_fingerprint' => 'SHA256:abc', 'encryption_password' => 'short']));
        $this->expectException(BackupException::class);
        app(BackupService::class)->run();
    }

    public function test_remote_retention_keeps_newest_n_backups_only(): void
    {
        foreach (['20200101_000000', '20200102_000000', '20200103_000000'] as $d) {
            file_put_contents("{$this->remoteDir}/DomainDash_{$d}.zip", 'old');
        }
        file_put_contents("{$this->remoteDir}/unrelated.zip", 'keep me');

        app(BackupService::class)->run();

        $names = array_map('basename', glob($this->remoteDir . '/*.zip'));
        sort($names);
        $this->assertCount(3, $names); // 2 kept backups + unrelated file
        $this->assertContains('unrelated.zip', $names);
        $this->assertNotContains('DomainDash_20200101_000000.zip', $names);
        $this->assertNotContains('DomainDash_20200102_000000.zip', $names);
    }

    public function test_upload_failure_cleans_up_and_records_failure(): void
    {
        $this->app->bind(RemoteStorageFactory::class, fn () => new class extends RemoteStorageFactory {
            public function make(array $backup): FilesystemOperator
            {
                throw new \RuntimeException('connection refused');
            }
        });

        try {
            app(BackupService::class)->run();
            $this->fail('expected failure');
        } catch (BackupException $e) {
            $this->assertStringContainsString('connection refused', $e->getMessage());
        }
        $this->assertSame([], $this->localTemps());
        $this->assertTrue(AuditLog::where('action', 'backup.failed')->exists());
    }

    public function test_mysql_password_goes_through_a_private_defaults_file_not_the_command_line(): void
    {
        $log = sys_get_temp_dir() . '/dd-dump-log-' . bin2hex(random_bytes(4));
        $fake = sys_get_temp_dir() . '/dd-fake-dump-' . bin2hex(random_bytes(4)) . '.sh';
        file_put_contents($fake, <<<SH
#!/bin/sh
echo "ARGS: \$*" > $log
stat -c '%a' "\${1#--defaults-extra-file=}" >> $log
cat "\${1#--defaults-extra-file=}" >> $log
for a in "\$@"; do case "\$a" in --result-file=*) echo "-- dump" > "\${a#--result-file=}";; esac; done
SH);
        chmod($fake, 0700);

        $dbPw = FakeCredential::secret('db');
        config([
            'domaindash.backup.connection' => 'mysql',
            'database.connections.mysql.password' => $dbPw,
            'database.connections.mysql.username' => 'dbuser',
            'database.connections.mysql.database' => 'domaindash',
            'domaindash.backup.dump_binary' => $fake,
        ]);

        $result = app(BackupService::class)->run();

        $out = file_get_contents($log);
        $lines = explode("\n", $out);
        $this->assertStringNotContainsString($dbPw, $lines[0], 'password must not be on the command line');
        $this->assertSame('600', $lines[1]);
        $this->assertStringContainsString('password="'.$dbPw.'"', $out);
        $this->assertSame([], $this->localTemps());
        $this->assertFileExists($this->remoteDir . '/' . $result['file']);

        @unlink($fake);
        @unlink($log);
    }

    public function test_real_factory_builds_a_lazy_sftp_filesystem_without_connecting(): void
    {
        $fs = (new RemoteStorageFactory())->make([
            'host' => 'backup.invalid', 'username' => 'u', 'password' => 'p', 'port' => 22, 'path' => '/b',
            'host_fingerprint' => 'SHA256:abc',
        ]);
        $this->assertInstanceOf(FilesystemOperator::class, $fs);
    }

    public function test_settings_page_shows_last_successful_backup(): void
    {
        $admin = \App\Models\User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Administrator'));

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('No successful backup yet');

        app(BackupService::class)->run();

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Last successful backup:');
    }
}
