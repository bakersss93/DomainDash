<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SessionRevoker
{
    /**
     * Delete a user's stored sessions (database session driver), optionally
     * keeping the current one. No-op for other drivers.
     */
    public static function revoke(User $user, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $q = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey());

        if ($exceptSessionId) {
            $q->where('id', '!=', $exceptSessionId);
        }

        return $q->delete();
    }
}
