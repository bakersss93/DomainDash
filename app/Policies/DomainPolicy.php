<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Domain;

class DomainPolicy
{
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

    public function runSync(User $user): bool
    {
        return $user->hasRole('Administrator') && $user->can('sync.run');
    }
}
