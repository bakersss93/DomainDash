<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Domain;

class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('Administrator')) return true;
        if ($user->hasRole('Technician')) return true;

        return $user->clients()->exists();
    }

    public function view(User $user, Domain $domain): bool
    {
        if ($user->hasRole('Administrator')) return true;
        if ($user->hasRole('Technician')) return true; // technicians can view all
        // customers: restrict to their clients
        return $user->clients()->where('clients.id', $domain->client_id)->exists();
    }

    public function update(User $user, Domain $domain): bool
    {
        if ($user->hasRole('Administrator')) return true;
        if ($user->hasRole('Technician') && $user->can('dns.manage')) return true;
        return false;
    }

    /**
     * Manage DNS records / nameservers. Staff need dns.manage; any other user
     * needs dns.manage AND an assignment to the domain's client.
     */
    public function manageDns(User $user, Domain $domain): bool
    {
        if (! $user->can('dns.manage')) return false;
        if ($user->hasRole('Administrator') || $user->hasRole('Technician')) return true;

        return $domain->client_id !== null
            && $user->clients()->where('clients.id', $domain->client_id)->exists();
    }

    public function runSync(User $user): bool
    {
        return $user->hasRole('Administrator') && $user->can('sync.run');
    }
}
