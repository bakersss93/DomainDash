<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoragePermissionsTest extends TestCase
{
    public function test_local_disk_is_private_and_not_served(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertSame(0600, config('filesystems.disks.local.permissions.file.private'));
        $this->assertSame(0700, config('filesystems.disks.local.permissions.dir.private'));
    }

    public function test_files_written_to_the_local_disk_are_0600_and_directories_0700(): void
    {
        $root = sys_get_temp_dir() . '/dd-perm-' . bin2hex(random_bytes(4));
        config(['filesystems.disks.perm' => array_merge(config('filesystems.disks.local'), ['root' => $root])]);

        Storage::disk('perm')->put('vocus/client.p12', 'secret');
        clearstatcache();

        $this->assertSame('600', substr(sprintf('%o', fileperms("$root/vocus/client.p12")), -3));
        $this->assertSame('700', substr(sprintf('%o', fileperms("$root/vocus")), -3));

        @unlink("$root/vocus/client.p12");
        @rmdir("$root/vocus");
        @rmdir($root);
    }

    public function test_deploy_script_locks_down_storage_and_database(): void
    {
        $sh = file_get_contents(base_path('deploy/deploy.sh'));
        $this->assertStringContainsString('chmod -R o-rwx storage database', $sh);
    }
}
