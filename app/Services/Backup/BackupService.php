<?php

namespace App\Services\Backup;

use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Nightly backup: database dump -> AES-256 encrypted zip -> SFTP (host key pinned) -> retention.
 *
 * Security properties:
 *  - the database password never appears on a command line (mysqldump reads a 0600 --defaults-extra-file);
 *  - the archive is encrypted with the "backup encryption password" setting, and the run is refused without one;
 *  - the SFTP server must match the stored host fingerprint, and the run is refused without one;
 *  - every local temporary file is 0600 inside a 0700 directory and is removed in `finally`;
 *  - success and failure are written to the audit log; the last success is stored for the Settings page.
 */
class BackupService
{
    public const STATUS_KEY = 'backup_status';

    public function __construct(private RemoteStorageFactory $remote)
    {
    }

    /** Is a backup destination configured at all (host set)? */
    public static function isConfigured(): bool
    {
        $b = (array) Setting::get('backup', []);

        return ! empty($b['host']);
    }

    /** @return array{file:string,size:int,deleted:int} */
    public function run(): array
    {
        $dir = $this->workDir();
        $temps = [];

        try {
            $backup = (array) Setting::get('backup', []);
            $this->assertConfigured($backup);

            $stamp = now()->format('Ymd_His');
            $filename = "DomainDash_{$stamp}.zip";

            $dump = $this->dumpDatabase($dir, $temps);
            $zipPath = $this->temp($dir, $temps, 'zip');
            $this->makeEncryptedZip($zipPath, $dump['path'], $dump['name'], (string) $backup['encryption_password']);

            $size = (int) filesize($zipPath);
            $this->upload($backup, $zipPath, $filename);
            $deleted = $this->applyRetention($backup);

            $this->recordSuccess($filename, $size);

            return ['file' => $filename, 'size' => $size, 'deleted' => $deleted];
        } catch (\Throwable $e) {
            $message = $e instanceof BackupException
                ? $e->getMessage()
                : 'Unexpected error (' . class_basename($e) . '): ' . mb_substr($e->getMessage(), 0, 200);
            $this->recordFailure($message);

            throw $e instanceof BackupException ? $e : new BackupException($message, 0, $e);
        } finally {
            foreach ($temps as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    // ------------------------------------------------------------------

    private function assertConfigured(array $backup): void
    {
        if (empty($backup['host'])) {
            throw new BackupException('Backup destination is not configured.');
        }
        if (empty($backup['host_fingerprint'])) {
            throw new BackupException('Backup refused: the SFTP host fingerprint is not set (Settings > Backups).');
        }
        if (empty($backup['encryption_password']) || mb_strlen((string) $backup['encryption_password']) < 12) {
            throw new BackupException('Backup refused: set a backup encryption password of at least 12 characters (Settings > Backups).');
        }
        if (! class_exists(\ZipArchive::class) || ! defined('ZipArchive::EM_AES_256')) {
            throw new BackupException('Backup refused: the PHP zip extension with AES-256 support is required.');
        }
    }

    private function workDir(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);

        return $dir;
    }

    /** Create an empty 0600 file in the work dir and register it for cleanup. */
    private function temp(string $dir, array &$temps, string $ext): string
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'tmp_' . bin2hex(random_bytes(8)) . '.' . $ext;
        touch($path);
        chmod($path, 0600);
        $temps[] = $path;

        return $path;
    }

    /** @return array{path:string,name:string} */
    private function dumpDatabase(string $dir, array &$temps): array
    {
        $connection = (string) (config('domaindash.backup.connection') ?: config('database.default'));
        $cfg = (array) config("database.connections.{$connection}");

        return match ($cfg['driver'] ?? null) {
            'sqlite' => $this->dumpSqlite($dir, $temps, $cfg),
            'mysql', 'mariadb' => $this->dumpMysql($dir, $temps, $cfg),
            default => throw new BackupException('Backups are supported for MySQL/MariaDB and SQLite databases only.'),
        };
    }

    private function dumpSqlite(string $dir, array &$temps, array $cfg): array
    {
        $source = (string) ($cfg['database'] ?? '');
        if ($source === '' || $source === ':memory:' || ! is_file($source)) {
            throw new BackupException('SQLite database file was not found.');
        }

        $dest = $this->temp($dir, $temps, 'sqlite');

        // VACUUM INTO gives a consistent snapshot (includes the WAL); it cannot run inside a transaction.
        $done = false;
        if (DB::transactionLevel() === 0) {
            try {
                @unlink($dest);
                DB::connection()->statement('VACUUM INTO ?', [$dest]);
                chmod($dest, 0600);
                $done = is_file($dest) && filesize($dest) > 0;
            } catch (\Throwable) {
                $done = false;
            }
        }
        if (! $done) {
            if (! @copy($source, $dest)) {
                throw new BackupException('Could not copy the SQLite database file.');
            }
            chmod($dest, 0600);
        }

        return ['path' => $dest, 'name' => 'database.sqlite'];
    }

    private function dumpMysql(string $dir, array &$temps, array $cfg): array
    {
        $dest = $this->temp($dir, $temps, 'sql');
        $defaults = $this->temp($dir, $temps, 'cnf');

        $lines = ['[client]'];
        $lines[] = 'user=' . $this->cnfValue((string) ($cfg['username'] ?? ''));
        $lines[] = 'password=' . $this->cnfValue((string) ($cfg['password'] ?? ''));
        if (! empty($cfg['unix_socket'])) {
            $lines[] = 'socket=' . $this->cnfValue((string) $cfg['unix_socket']);
        } else {
            $lines[] = 'host=' . $this->cnfValue((string) ($cfg['host'] ?? '127.0.0.1'));
            $lines[] = 'port=' . (int) ($cfg['port'] ?? 3306);
        }
        file_put_contents($defaults, implode("\n", $lines) . "\n");

        // --defaults-extra-file must be the first option.
        $process = new Process([
            (string) config('domaindash.backup.dump_binary', 'mysqldump'),
            '--defaults-extra-file=' . $defaults,
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces',
            '--result-file=' . $dest,
            (string) $cfg['database'],
        ]);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($dest) || filesize($dest) === 0) {
            // stderr is deliberately not echoed: keep the message free of host / credential detail.
            throw new BackupException('Database dump failed (exit code ' . $process->getExitCode() . ').');
        }

        return ['path' => $dest, 'name' => 'database.sql'];
    }

    private function cnfValue(string $v): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $v) . '"';
    }

    private function makeEncryptedZip(string $zipPath, string $file, string $entry, string $password): void
    {
        @unlink($zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('Zip creation failed.');
        }
        $zip->setPassword($password);
        if (! $zip->addFile($file, $entry) || ! $zip->setEncryptionName($entry, \ZipArchive::EM_AES_256, $password)) {
            $zip->close();
            throw new BackupException('Could not encrypt the backup archive.');
        }
        if (! $zip->close()) {
            throw new BackupException('Zip creation failed.');
        }
        chmod($zipPath, 0600);
    }

    private function upload(array $backup, string $zipPath, string $filename): void
    {
        $remote = $this->remote->make($backup);
        $stream = fopen($zipPath, 'rb');
        try {
            $remote->writeStream($filename, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function applyRetention(array $backup): int
    {
        $remote = $this->remote->make($backup);
        $keep = max(1, (int) ($backup['retention'] ?? 7));

        $names = [];
        foreach ($remote->listContents('', false) as $item) {
            $base = basename($item->path());
            if ($item->isFile() && preg_match('/^DomainDash_\d{8}_\d{6}\.zip$/', $base)) {
                $names[] = $item->path();
            }
        }
        rsort($names, SORT_STRING);

        $deleted = 0;
        foreach (array_slice($names, $keep) as $old) {
            $remote->delete($old);
            $deleted++;
        }

        return $deleted;
    }

    private function recordSuccess(string $file, int $size): void
    {
        $status = (array) Setting::get(self::STATUS_KEY, []);
        $status['last_success_at'] = now()->toIso8601String();
        $status['last_file'] = $file;
        $status['last_size'] = $size;
        $status['last_error'] = null;
        $status['last_failure_at'] = null;
        Setting::put(self::STATUS_KEY, $status);

        AuditLogger::logSystem('backup.completed', "Backup completed: {$file}", ['service' => 'backup', 'size' => $size]);
    }

    private function recordFailure(string $message): void
    {
        try {
            $status = (array) Setting::get(self::STATUS_KEY, []);
            $status['last_failure_at'] = now()->toIso8601String();
            $status['last_error'] = $message;
            Setting::put(self::STATUS_KEY, $status);

            AuditLogger::logSystem('backup.failed', 'Backup failed: ' . $message, ['service' => 'backup']);
        } catch (\Throwable) {
            // never mask the original failure
        }
    }

    /** @return array{last_success_at:?string,last_file:?string,last_size:?int,last_failure_at:?string,last_error:?string} */
    public static function status(): array
    {
        $s = (array) Setting::get(self::STATUS_KEY, []);

        return [
            'last_success_at' => $s['last_success_at'] ?? null,
            'last_file' => $s['last_file'] ?? null,
            'last_size' => $s['last_size'] ?? null,
            'last_failure_at' => $s['last_failure_at'] ?? null,
            'last_error' => $s['last_error'] ?? null,
        ];
    }
}
