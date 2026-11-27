<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * Answers a failed reset-link request exactly like a successful one so the form cannot be
 * used to discover which email addresses have accounts.
 */
class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        $message = trans(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? response()->json(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
