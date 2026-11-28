<?php

namespace App\Services\Backup;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

/**
 * Builds the SFTP destination. The host key is verified against the stored fingerprint, so a man in the middle
 * (or a re-pointed DNS name) cannot receive the backup. Tests bind a fake factory in the container.
 */
class RemoteStorageFactory
{
    public function make(array $backup): FilesystemOperator
    {
        $provider = new SftpConnectionProvider(
            host: (string) $backup['host'],
            username: (string) ($backup['username'] ?? ''),
            password: ($backup['password'] ?? '') !== '' ? (string) $backup['password'] : null,
            privateKey: null,
            passphrase: null,
            port: (int) ($backup['port'] ?? 22),
            useAgent: false,
            timeout: 30,
            maxTries: 2,
            hostFingerprint: (string) $backup['host_fingerprint'],
        );

        return new Filesystem(new SftpAdapter($provider, (string) (($backup['path'] ?? '') !== '' ? $backup['path'] : '/')));
    }
}
