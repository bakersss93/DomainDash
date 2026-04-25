<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use League\Flysystem\Filesystem;
use League\Flysystem\Sftp\SftpAdapter;

class BackupRunCommand extends Command
{
    protected $signature = 'domaindash:backup-run';
    protected $description = 'Dump MySQL, zip, upload to SFTP, apply retention';

    public function handle(): int
    {
        $backup = Setting::get('backup');
        if (!$backup || empty($backup['host'])) {
            $this->warn('Backup settings not configured.');
            return 0;
        }

        $filename = 'DomainDash_' . now()->format('Ymd_His') . '.zip';
        $localPath = storage_path('app/backups/'.$filename);
        @mkdir(dirname($localPath), 0775, true);

        // 1) mysqldump
        $db = config('database.connections.mysql');
        $dumpPath = storage_path('app/backups/tmp.sql');
        $cmd = sprintf('mysqldump -u%s -p%s -h%s %s > %s 2>/dev/null',
            escapeshellarg($db['username']),
            escapeshellarg($db['password'] ?? ''),
            escapeshellarg($db['host'] ?? '127.0.0.1'),
            escapeshellarg($db['database']),
            escapeshellarg($dumpPath));
        system($cmd, $code);
        if ($code !== 0) {
            $this->error('mysqldump failed.');
            return 1;
        }

        // 2) zip
        $zip = new \ZipArchive();
        if ($zip->open($localPath, \ZipArchive::CREATE) === true) {
            $zip->addFile($dumpPath, 'database.sql');
            $zip->close();
        } else {
            $this->error('Zip creation failed.');
            return 1;
        }
        @unlink($dumpPath);

        // 3) upload SFTP
        $filesystem = new Filesystem(new SftpAdapter([
            'host' => $backup['host'],
            'port' => $backup['port'] ?? 22,
            'username' => $backup['username'],
            'password' => $backup['password'],
            'root' => $backup['path'] ?? '/',
            'timeout' => 30,
        ]));
        $stream = fopen($localPath, 'r');
        $filesystem->writeStream($filename, $stream);
        if (is_resource($stream)) fclose($stream);

        // 4) retention
        $keep = max(1, (int)($backup['retention'] ?? 7));
        $listing = $filesystem->listContents('', false);
        $zips = array_values(array_filter($listing, fn($x) => ($x['type'] ?? '') === 'file' && str_ends_with($x['path'], '.zip')));
        usort($zips, fn($a,$b) => strcmp($b['path'], $a['path']));
        foreach (array_slice($zips, $keep) as $old) {
            $filesystem->delete($old['path']);
        }

        $this->info('Backup completed: '.$filename);
        return 0;
    }
}
