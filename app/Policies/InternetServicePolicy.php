<?php

namespace App\Policies;

use App\Models\InternetService;
use App\Models\User;

/**
 * Customer-area access to internet services. Staff (Administrator / Technician) are never limited by client
 * assignment; every other user needs the permission AND an assignment to the service's client, the same rule as
 * DomainPolicy. Carrier-affecting actions (orders, cancellations) are never done by customers: they only raise tickets.
 */
class InternetServicePolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->can('internet.view') && $user->clients()->exists();
    }

    public function view(User $user, InternetService $service): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return $user->can('internet.view') && $this->owns($user, $service);
    }

    /** Customer diagnostics: permission + own service + the client's flag. */
    public function diagnose(User $user, InternetService $service): bool
    {
        if (! $user->can('internet.diagnostics') || ! $this->owns($user, $service)) {
            return false;
        }

        return (bool) $service->client?->internet_diagnostics_enabled;
    }

    /** Raise a cancellation ticket for an own service (the admin setting is checked by the controller). */
    public function requestCancellation(User $user, InternetService $service): bool
    {
        return $user->can('internet.cancel') && $this->owns($user, $service);
    }

    /** Raise a new-service request ticket (the admin setting is checked by the controller). */
    public function requestNew(User $user): bool
    {
        return $user->can('internet.order') && $user->clients()->exists();
    }

    private function owns(User $user, InternetService $service): bool
    {
        return $service->client_id !== null
            && $user->clients()->where('clients.id', $service->client_id)->exists();
    }
}
