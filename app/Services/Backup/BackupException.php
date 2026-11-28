<?php

namespace App\Services\Backup;

/** A backup failure whose message is safe to show to administrators (never contains secrets). */
class BackupException extends \RuntimeException
{
}
