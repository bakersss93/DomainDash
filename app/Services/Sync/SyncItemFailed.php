<?php

namespace App\Services\Sync;

/**
 * A single item cannot be synced (missing data / not linked). Not retryable;
 * the message is stored on the item and counted in the SyncRun.
 */
class SyncItemFailed extends \RuntimeException
{
}
