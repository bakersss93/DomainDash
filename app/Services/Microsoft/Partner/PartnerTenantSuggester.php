<?php

namespace App\Services\Microsoft\Partner;

use App\Models\Client;
use App\Models\Domain;
use App\Models\PartnerCustomerTenant;
use Illuminate\Support\Collection;

/**
 * Suggests which DomainDash client a partner customer tenant probably is. Suggestions only: an administrator
 * always confirms. Evidence, strongest first:
 *   1. the tenant's custom domain (contracts) equals a domain DomainDash manages for the client or the domain of
 *      the client's / its users' e-mail addresses (free mail providers are ignored);
 *   2. the display name (or the *.onmicrosoft.com prefix) resembles the client's business name.
 */
class PartnerTenantSuggester
{
    public const NAME_THRESHOLD = 0.82;

    private const FREE_MAIL = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'yahoo.com.au', 'icloud.com', 'me.com', 'bigpond.com', 'bigpond.net.au', 'optusnet.com.au', 'aol.com', 'proton.me', 'protonmail.com', 'msn.com'];

    private const NOISE_WORDS = ['pty', 'ltd', 'limited', 'inc', 'incorporated', 'llc', 'corp', 'corporation', 'co', 'company', 'the', 'and', 'group', 'holdings', 'australia', 'au', 'trust', 'services'];

    /** @var Collection<int,array{client:Client,name:string,domains:array<string,string>}>|null */
    private ?Collection $index = null;

    /**
     * @param  Collection<int,Client>|null  $clients  candidate clients (default: every client without a tenant)
     * @return array{client:Client,score:float,reason:string}|null
     */
    public function suggest(PartnerCustomerTenant $tenant, ?Collection $clients = null): ?array
    {
        $candidates = $this->index($clients);

        $tenantDomains = collect(array_merge([$tenant->default_domain], (array) $tenant->domains))
            ->filter()->map(fn ($d) => strtolower((string) $d))
            ->reject(fn ($d) => str_ends_with($d, '.onmicrosoft.com'))->unique()->values();

        $best = null;

        foreach ($candidates as $row) {
            foreach ($tenantDomains as $d) {
                if (isset($row['domains'][$d])) {
                    $cand = ['client' => $row['client'], 'score' => 1.0, 'reason' => "Domain match: {$d} ({$row['domains'][$d]})"];
                    if ($best === null || $cand['score'] > $best['score']) {
                        $best = $cand;
                    }
                }
            }
        }
        if ($best !== null) {
            return $best;
        }

        $names = array_filter([$tenant->display_name, $this->onMicrosoftPrefix($tenant)]);
        foreach ($candidates as $row) {
            foreach ($names as $n) {
                $score = self::nameSimilarity((string) $n, $row['client']->business_name);
                if ($score >= self::NAME_THRESHOLD && ($best === null || $score > $best['score'])) {
                    $best = ['client' => $row['client'], 'score' => round($score, 2), 'reason' => 'Name similarity '.round($score * 100).'%'];
                }
            }
        }

        return $best;
    }

    /** 0..1: containment of the normalised names counts as a strong match, otherwise similar_text / levenshtein. */
    public static function nameSimilarity(string $a, string $b): float
    {
        $a = self::normaliseName($a);
        $b = self::normaliseName($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $score = 0.0;
        if (min(strlen($a), strlen($b)) >= 5 && (str_contains($a, $b) || str_contains($b, $a))) {
            $score = 0.9;
        }

        similar_text($a, $b, $pct);
        $score = max($score, $pct / 100);

        $len = max(strlen($a), strlen($b));
        if ($len <= 255) {
            $score = max($score, 1 - levenshtein($a, $b) / $len);
        }

        return $score;
    }

    public static function normaliseName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace('&', ' and ', $name);
        $words = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_filter($words, fn ($w) => ! in_array($w, self::NOISE_WORDS, true)));

        return implode('', $kept === [] ? $words : $kept);
    }

    private function onMicrosoftPrefix(PartnerCustomerTenant $tenant): ?string
    {
        $d = strtolower((string) $tenant->default_domain);

        return str_ends_with($d, '.onmicrosoft.com') ? substr($d, 0, -strlen('.onmicrosoft.com')) : null;
    }

    /** @return Collection<int,array{client:Client,name:string,domains:array<string,string>}> */
    private function index(?Collection $clients): Collection
    {
        if ($clients === null && $this->index !== null) {
            return $this->index;
        }

        $cache = $clients === null;
        $clients ??= Client::query()->whereNull('microsoft_tenant_id')->orWhere('microsoft_tenant_id', '')->get();
        $ids = $clients->pluck('id')->all();

        $domains = Domain::query()->whereIn('client_id', $ids)->get(['client_id', 'name'])->groupBy('client_id');
        $userEmails = \Illuminate\Support\Facades\DB::table('client_user')
            ->join('users', 'users.id', '=', 'client_user.user_id')
            ->whereIn('client_user.client_id', $ids)
            ->get(['client_user.client_id', 'users.email'])->groupBy('client_id');

        $rows = $clients->map(function (Client $client) use ($domains, $userEmails) {
            $map = [];
            foreach ($domains->get($client->id, collect()) as $d) {
                $map[strtolower((string) $d->name)] = 'managed domain';
            }
            $emails = array_merge([$client->email], $userEmails->get($client->id, collect())->pluck('email')->all());
            foreach ($emails as $email) {
                $host = strtolower(trim((string) substr(strrchr((string) $email, '@') ?: '', 1)));
                if ($host !== '' && ! in_array($host, self::FREE_MAIL, true)) {
                    $map[$host] ??= 'e-mail domain';
                }
            }

            return ['client' => $client, 'name' => (string) $client->business_name, 'domains' => $map];
        })->values();

        return $cache ? ($this->index = $rows) : $rows;
    }
}
