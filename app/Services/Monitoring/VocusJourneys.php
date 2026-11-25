<?php

namespace App\Services\Monitoring;

use App\Models\AuditLog;
use App\Models\IntegrationRequest;
use App\Models\InternetService;
use App\Models\InternetServiceDiagnostic;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Vocus production certification: turns the recorded WSM calls into "journeys" per location / service
 *   address lookup (DIR Get) -> SQ (FIBRE Get QUALIFY) -> order (FIBRE Create) -> async follow-up
 *   (TCAS Get RESPONSE / NOTIFICATIONS) -> service Get (FIBRE Get SERVICE)
 * correlated by DirectoryID (LOC id), ServiceID and the Create TransactionID, plus the single calls that belong
 * to no journey (AUTH-LOG, DISCONNECT, Set STATUS ...), grouped by method.
 *
 * Steps come from integration_requests (always recorded for Vocus) and, where possible, from older data that
 * only exists on service records / the activity trail (marked source "service record").
 */
class VocusJourneys
{
    public const SOURCE_RECORDED = 'recorded';
    public const SOURCE_SERVICE = 'service record';

    private const LIMIT = 5000;

    /** Plain-language hints appended to the method names Vocus knows. */
    private const HINTS = [
        'DIR::Get' => 'address lookup',
        'DIR::Create' => 'directory entry',
        'TCAS::Get RESPONSE' => 'transaction poll',
        'FIBRE::Get NOTIFICATIONS' => 'order notifications',
    ];

    public const CSV_HEADER = ['journey', 'journey_status', 'step', 'method', 'product_id', 'operation', 'plan_id', 'scope', 'transaction_id',
        'related_transaction_id', 'timestamp', 'response_type', 'result', 'directory_id', 'service_id', 'avc_id', 'environment', 'source'];

    /**
     * @param array{environment?:string,from?:string,to?:string} $filters
     * @return array{journeys: list<array<string,mixed>>, singles: list<array<string,mixed>>, methods: list<array<string,mixed>>, stats: array<string,int>, filters: array<string,string>}
     */
    public function build(array $filters = []): array
    {
        $env = ($filters['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
        $from = $this->date($filters['from'] ?? null, false);
        $to = $this->date($filters['to'] ?? null, true);

        $steps = $this->recordedSteps($env, $from, $to);
        $steps = array_merge($steps, $this->serviceRecordSteps($env, $from, $to, $steps));
        usort($steps, fn ($a, $b) => [$a['at']->getTimestamp(), $a['id'] ?? 0] <=> [$b['at']->getTimestamp(), $b['id'] ?? 0]);

        [$journeys, $leftover] = $this->correlate($steps);
        $singles = array_values(array_filter($leftover, fn ($s) => $s['transaction_id'] !== null));

        return [
            'journeys' => $journeys,
            'singles' => $this->groupSingles($singles),
            'methods' => $this->groupByMethod($steps),
            'stats' => [
                'calls' => count($steps),
                'transactions' => count(array_filter($steps, fn ($s) => $s['transaction_id'] !== null)),
                'journeys' => count($journeys),
                'complete' => count(array_filter($journeys, fn ($j) => $j['complete'])),
                'from_service_record' => count(array_filter($steps, fn ($s) => $s['source'] !== self::SOURCE_RECORDED)),
            ],
            'filters' => ['environment' => $env, 'from' => $from?->toDateString() ?? '', 'to' => $to?->toDateString() ?? ''],
        ];
    }

    /**
     * Sandbox checklist: which certification steps already have a recorded sandbox transaction.
     *
     * @return array{steps: list<array{key:string,label:string,done:bool,detail:?string}>, complete: bool, environment:string}
     */
    public function checklist(string $environment = 'sandbox'): array
    {
        $data = $this->build(['environment' => $environment]);
        $all = collect($this->flatten($data));
        $done = fn (string ...$kinds) => $all->contains(fn ($s) => in_array($s['kind'], $kinds, true) && $s['transaction_id'] !== null && $s['ok']);
        $last = fn (string ...$kinds) => $all->filter(fn ($s) => in_array($s['kind'], $kinds, true) && $s['transaction_id'] !== null)->sortBy(fn ($s) => $s['at']->getTimestamp())->last();
        $detail = fn (?array $s) => $s ? 'TransactionID ' . $s['transaction_id'] . ', ' . $s['at']->format('d M Y H:i') : null;

        return [
            'steps' => [
                ['key' => 'qualify', 'label' => 'Qualify an address or LOC ID (FIBRE Get QUALIFY)', 'done' => $done('qualify'), 'detail' => $detail($last('qualify'))],
                ['key' => 'order', 'label' => 'Place an order (FIBRE Create)', 'done' => $done('order'), 'detail' => $detail($last('order'))],
                ['key' => 'poll', 'label' => 'Wait for polling (TCAS Get RESPONSE)', 'done' => $done('poll', 'notifications'), 'detail' => $detail($last('poll', 'notifications'))],
                ['key' => 'service', 'label' => 'View the service (FIBRE Get SERVICE)', 'done' => $done('service'), 'detail' => $detail($last('service'))],
            ],
            'complete' => $data['stats']['complete'] > 0,
            'environment' => $environment,
        ];
    }

    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function recordedSteps(string $env, ?Carbon $from, ?Carbon $to): array
    {
        if (! Schema::hasColumn('integration_requests', 'product_id')) {
            return [];
        }

        $query = IntegrationRequest::query()
            ->where('provider', 'vocus')->where('direction', IntegrationRequest::OUTBOUND)
            ->whereNotNull('product_id')->where('environment', $env);
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $rows = $query->orderBy('id')->limit(self::LIMIT)->get([
            'id', 'created_at', 'operation', 'environment', 'product_id', 'plan_id', 'scope', 'transaction_id', 'related_transaction_id',
            'response_type', 'result', 'directory_id', 'service_id', 'avc_id', 'meta', 'is_error',
        ]);

        // NOTIFICATIONS carry no ids of their own: read their (redacted) body once to correlate them.
        $bodies = IntegrationRequest::query()->whereIn('id', $rows->where('scope', 'NOTIFICATIONS')->pluck('id'))->pluck('response_body', 'id');

        return $rows->map(fn (IntegrationRequest $r) => $this->step([
            'id' => $r->id, 'at' => $r->created_at, 'operation' => (string) $r->operation, 'environment' => $r->environment,
            'product' => (string) $r->product_id, 'plan_id' => $r->plan_id, 'scope' => $r->scope,
            'transaction_id' => $r->transaction_id, 'related_transaction_id' => $r->related_transaction_id,
            'response_type' => $r->response_type, 'result' => $r->result, 'ok' => ! $r->is_error && ($r->result === null || $r->result === '' || str_starts_with(strtoupper((string) $r->result), 'OK')),
            'directory_id' => $r->directory_id, 'service_id' => $r->service_id, 'avc_id' => $r->avc_id,
            'meta' => (array) ($r->meta ?? []), 'source' => self::SOURCE_RECORDED, 'body' => $bodies[$r->id] ?? null,
        ]))->all();
    }

    /**
     * Older transaction ids that only live on service records, diagnostics and the activity trail.
     * Their environment was never stored, so they are assumed to belong to the environment configured now.
     *
     * @param list<array<string,mixed>> $known
     * @return list<array<string,mixed>>
     */
    private function serviceRecordSteps(string $env, ?Carbon $from, ?Carbon $to, array $known): array
    {
        try {
            $current = $this->currentEnvironment();
            if ($current !== $env) {
                return [];
            }

            $seen = [];
            foreach ($known as $s) {
                if ($s['transaction_id'] !== null) {
                    $seen[$s['transaction_id']] = true;
                }
            }
            $inRange = fn (?Carbon $at) => $at && (! $from || $at >= $from) && (! $to || $at <= $to);
            $out = [];

            // Activity trail: orders and status changes that stored their transaction id.
            $audit = [];
            AuditLog::query()->where('action', 'like', 'internet.%')->where('new_values', 'like', '%transaction_id%')
                ->orderBy('id')->limit(2000)->get()->each(function (AuditLog $log) use (&$audit) {
                    $new = (array) ($log->new_values ?? []);
                    if (! empty($new['transaction_id'])) {
                        $audit[(string) $new['transaction_id']] = [$log->action, $new, $log->created_at];
                    }
                });

            InternetService::query()->whereNotNull('last_transaction_id')->orderBy('id')->limit(self::LIMIT)->get()
                ->each(function (InternetService $svc) use (&$out, &$seen, $audit, $inRange, $current) {
                    $tx = (string) $svc->last_transaction_id;
                    if (isset($seen[$tx])) {
                        return;
                    }
                    $seen[$tx] = true;
                    [$action, , $auditAt] = $audit[$tx] ?? [null, [], null];
                    $isStatus = $action === 'internet.set_status';
                    $at = $auditAt ?? $svc->created_at;
                    if (! $inRange($at)) {
                        return;
                    }
                    $out[] = $this->step([
                        'id' => null, 'at' => $at, 'operation' => $isStatus ? 'Set' : 'Create', 'environment' => $current,
                        'product' => 'FIBRE', 'plan_id' => $svc->plan_id, 'scope' => $isStatus ? 'STATUS' : $svc->service_scope,
                        'transaction_id' => $tx, 'related_transaction_id' => null, 'response_type' => null,
                        'result' => 'OK', 'ok' => true, 'directory_id' => $svc->directory_id, 'service_id' => $svc->vocus_service_id,
                        'avc_id' => $svc->avc_id,
                        'meta' => array_filter(['order_type' => $isStatus ? null : ($svc->order_type ?: 'NEW'), 'transaction_state' => $svc->last_transaction_state]),
                        'source' => self::SOURCE_SERVICE, 'body' => null,
                    ]);

                    // The service was polled: evidence of the async follow-up, although the poll's own id was not kept.
                    if (! $isStatus && $svc->last_polled_at && $inRange($svc->last_polled_at)) {
                        $out[] = $this->step([
                            'id' => null, 'at' => $svc->last_polled_at, 'operation' => 'Get', 'environment' => $current,
                            'product' => 'TCAS', 'plan_id' => 'TRANSACTION-ID', 'scope' => 'RESPONSE',
                            'transaction_id' => null, 'related_transaction_id' => $tx, 'response_type' => null,
                            'result' => 'OK', 'ok' => true, 'directory_id' => null, 'service_id' => $svc->vocus_service_id, 'avc_id' => null,
                            'meta' => array_filter(['transaction_state' => $svc->last_transaction_state]),
                            'source' => self::SOURCE_SERVICE, 'body' => null,
                        ]);
                    }
                });

            if (Schema::hasTable('internet_service_diagnostics')) {
                InternetServiceDiagnostic::query()->whereNotNull('transaction_id')->with('service:id,vocus_service_id,plan_id')->orderBy('id')->limit(2000)->get()
                    ->each(function ($d) use (&$out, &$seen, $inRange, $current) {
                        $tx = (string) $d->transaction_id;
                        if (isset($seen[$tx]) || ! $inRange($d->created_at)) {
                            return;
                        }
                        $seen[$tx] = true;
                        $type = (string) $d->diagnostic_type;
                        $out[] = $this->step([
                            'id' => null, 'at' => $d->created_at, 'operation' => $type === 'DISCONNECT' ? 'Set' : 'Get', 'environment' => $current,
                            'product' => $type === 'NOTIFICATIONS' ? 'FIBRE' : 'OPER', 'plan_id' => $d->service?->plan_id, 'scope' => $type,
                            'transaction_id' => $tx, 'related_transaction_id' => null, 'response_type' => null, 'result' => 'OK', 'ok' => true,
                            'directory_id' => null, 'service_id' => $d->service?->vocus_service_id, 'avc_id' => null,
                            'meta' => array_filter(['transaction_state' => $d->transaction_state]), 'source' => self::SOURCE_SERVICE, 'body' => null,
                        ]);
                    });
            }

            // Set STATUS changes whose service no longer carries that transaction id.
            foreach ($audit as $tx => [$action, $new, $at]) {
                if ($action !== 'internet.set_status' || isset($seen[$tx]) || ! $inRange($at)) {
                    continue;
                }
                $seen[$tx] = true;
                $out[] = $this->step([
                    'id' => null, 'at' => $at, 'operation' => 'Set', 'environment' => $current, 'product' => 'FIBRE',
                    'plan_id' => null, 'scope' => 'STATUS', 'transaction_id' => (string) $tx, 'related_transaction_id' => null, 'response_type' => null,
                    'result' => 'OK', 'ok' => true, 'directory_id' => null, 'service_id' => null, 'avc_id' => null,
                    'meta' => array_filter(['service_status' => $new['status'] ?? null]), 'source' => self::SOURCE_SERVICE, 'body' => null,
                ]);
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function currentEnvironment(): string
    {
        try {
            return app(VocusWsmClient::class)->environment();
        } catch (\Throwable) {
            return config('services.vocus.environment', 'production') === 'sandbox' ? 'sandbox' : 'production';
        }
    }

    // ------------------------------------------------------------------
    // Classification
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $s */
    private function step(array $s): array
    {
        $product = strtoupper((string) $s['product']);
        $op = ucfirst(strtolower((string) $s['operation']));
        $scope = strtoupper((string) ($s['scope'] ?? ''));

        $kind = match (true) {
            $product === 'DIR' && $op === 'Get' => 'lookup',
            $product === 'DIR' && $op === 'Create' => 'dir_create',
            $product === 'FIBRE' && $op === 'Get' && $scope === 'QUALIFY' => 'qualify',
            $product === 'FIBRE' && $op === 'Create' => 'order',
            $product === 'TCAS' && $op === 'Get' => 'poll',
            $product === 'FIBRE' && $op === 'Get' && $scope === 'NOTIFICATIONS' => 'notifications',
            $product === 'FIBRE' && $op === 'Get' && $scope === 'SERVICE' => 'service',
            default => 'other',
        };

        // Create is told apart by OrderType (NEW / CHURN), DIR calls by product alone, the rest by Scope.
        $detail = match ($kind) {
            'order' => strtoupper(trim((string) ($s['meta']['order_type'] ?? ''))),
            'lookup', 'dir_create' => '',
            default => $scope,
        };
        $method = trim($product . '::' . $op . ' ' . $detail);
        $hint = self::HINTS[$method] ?? null;

        return $s + [
            'kind' => $kind,
            'method' => $method,
            'label' => $method . ($hint ? ' (' . $hint . ')' : ''),
        ];
    }

    // ------------------------------------------------------------------
    // Correlation
    // ------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $steps chronological
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>} [journeys, unattached steps]
     */
    private function correlate(array $steps): array
    {
        $used = [];
        $journeys = [];

        foreach ($steps as $i => $o) {
            if ($o['kind'] === 'order') {
                $used[$i] = true;
                $journeys[] = ['directory_id' => $o['directory_id'], 'service_id' => $o['service_id'], 'order_tx' => $o['transaction_id'], 'steps' => [$i], 'type' => 'order', 'start' => $o['at']];
            }
        }

        // Attach step $i to the first order journey that $matches; $before: the step must precede the order.
        $attach = function (int $i, callable $matches, bool $before = false) use (&$journeys, &$used, $steps) {
            if (isset($used[$i])) {
                return;
            }
            $s = $steps[$i];
            foreach ($journeys as $k => $j) {
                if ($j['type'] === 'order' && $matches($s, $j) && ($before ? $s['at'] <= $j['start'] : $s['at'] >= $j['start'])) {
                    $journeys[$k]['steps'][] = $i;
                    $used[$i] = true;

                    return;
                }
            }
        };

        foreach ($steps as $i => $s) {
            switch ($s['kind']) {
                case 'poll':
                    $attach($i, fn ($s, $j) => ($s['related_transaction_id'] !== null && $s['related_transaction_id'] === $j['order_tx'])
                        || ($j['service_id'] !== null && $s['service_id'] === $j['service_id']));
                    break;
                case 'service':
                    $attach($i, fn ($s, $j) => $j['service_id'] !== null && $s['service_id'] === $j['service_id']);
                    break;
                case 'notifications':
                    $attach($i, fn ($s, $j) => $s['body'] !== null
                        && (($j['order_tx'] && str_contains($s['body'], $j['order_tx'])) || ($j['service_id'] && str_contains($s['body'], $j['service_id']))));
                    break;
                case 'qualify':
                    $attach($i, fn ($s, $j) => $j['directory_id'] !== null && $s['directory_id'] === $j['directory_id'], true);
                    break;
            }
        }

        // Qualifications with no order: one journey per location.
        $byLoc = [];
        foreach ($steps as $i => $s) {
            if ($s['kind'] === 'qualify' && ! isset($used[$i])) {
                $byLoc[(string) ($s['directory_id'] ?? 'unknown-' . $i)][] = $i;
            }
        }
        foreach ($byLoc as $loc => $idx) {
            foreach ($idx as $i) {
                $used[$i] = true;
            }
            $journeys[] = ['directory_id' => str_starts_with((string) $loc, 'unknown-') ? null : (string) $loc, 'service_id' => null, 'order_tx' => null,
                'steps' => $idx, 'type' => 'qualification', 'start' => $steps[$idx[0]]['at']];
        }

        // Address lookups / directory entries: the first journey at that location that starts after them.
        foreach ($steps as $i => $s) {
            if (! in_array($s['kind'], ['lookup', 'dir_create'], true) || isset($used[$i])) {
                continue;
            }
            $ids = $s['kind'] === 'lookup' ? (array) ($s['meta']['directory_ids'] ?? []) : array_filter([$s['directory_id']]);
            $best = null;
            foreach ($journeys as $k => $j) {
                if ($j['directory_id'] !== null && in_array($j['directory_id'], $ids, true) && $j['start'] >= $s['at']
                    && ($best === null || $j['start'] < $journeys[$best]['start'])) {
                    $best = $k;
                }
            }
            if ($best !== null) {
                $journeys[$best]['steps'][] = $i;
                $used[$i] = true;
            }
        }

        $out = array_map(fn ($j) => $this->finishJourney($j, $steps), $journeys);
        usort($out, fn ($a, $b) => strcmp($b['started_at'], $a['started_at']));
        foreach ($out as $n => $j) {
            $out[$n]['id'] = 'J' . ($n + 1);
        }

        $leftover = [];
        foreach ($steps as $i => $s) {
            if (! isset($used[$i])) {
                $leftover[] = $s;
            }
        }

        return [$out, $leftover];
    }

    /** @param array<string,mixed> $j */
    private function finishJourney(array $j, array $steps): array
    {
        $list = array_map(fn ($i) => $steps[$i], $j['steps']);
        usort($list, fn ($a, $b) => [$a['at']->getTimestamp(), $a['id'] ?? 0] <=> [$b['at']->getTimestamp(), $b['id'] ?? 0]);

        $of = fn (string ...$kinds) => array_values(array_filter($list, fn ($s) => in_array($s['kind'], $kinds, true)));
        $okTx = fn (array $set) => (bool) array_filter($set, fn ($s) => $s['ok'] && $s['transaction_id'] !== null);

        $polls = $of('poll');
        $has = [
            'lookup' => (bool) $of('lookup', 'dir_create'),
            'qualify' => $okTx($of('qualify')),
            'order' => $okTx($of('order')),
            'followup' => (bool) array_filter(array_merge($polls, $of('notifications')), fn ($s) => $s['ok']),
            'service' => $okTx($of('service')),
        ];

        $missing = [];
        foreach ([['qualify', 'service qualification'], ['order', 'order'], ['followup', 'async follow-up']] as [$key, $label]) {
            if (! $has[$key]) {
                $missing[] = $label;
            }
        }

        $lastPoll = $polls ? end($polls) : null;
        $title = $j['service_id'] ? 'Service ' . $j['service_id'] : ($j['directory_id'] ? 'Location ' . $j['directory_id'] : 'Unlinked qualification');

        return [
            'id' => '',
            'title' => $title,
            'directory_id' => $j['directory_id'],
            'service_id' => $j['service_id'],
            'order_transaction_id' => $j['order_tx'],
            'type' => $j['type'],
            'started_at' => $list[0]['at']->toIso8601String(),
            'steps' => $list,
            'has' => $has,
            'complete' => $has['qualify'] && $has['order'] && $has['followup'],
            'missing' => $missing,
            'final_state' => $lastPoll['meta']['transaction_state'] ?? null,
            'poll_count' => count($polls),
        ];
    }

    // ------------------------------------------------------------------
    // Grouping
    // ------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $steps
     * @return list<array<string,mixed>> one entry per API method with its transaction ids (calls that have an id)
     */
    private function groupByMethod(array $steps): array
    {
        $groups = [];
        foreach ($steps as $s) {
            if ($s['transaction_id'] === null) {
                continue;
            }
            $groups[$s['method']]['method'] = $s['method'];
            $groups[$s['method']]['label'] = $s['label'];
            $groups[$s['method']]['calls'][] = $s;
        }
        ksort($groups);

        return array_values(array_map(function ($g) {
            $g['count'] = count($g['calls']);
            $g['ok'] = count(array_filter($g['calls'], fn ($c) => $c['ok']));

            return $g;
        }, $groups));
    }

    /**
     * @param list<array<string,mixed>> $singles
     * @return list<array<string,mixed>>
     */
    private function groupSingles(array $singles): array
    {
        $groups = [];
        foreach ($singles as $s) {
            $groups[$s['method']]['method'] = $s['method'];
            $groups[$s['method']]['label'] = $s['label'];
            $groups[$s['method']]['calls'][] = $s;
        }
        ksort($groups);

        return array_values($groups);
    }

    /** @return list<array<string,mixed>> */
    private function flatten(array $data): array
    {
        $all = [];
        foreach ($data['journeys'] as $j) {
            array_push($all, ...$j['steps']);
        }
        foreach ($data['singles'] as $g) {
            array_push($all, ...$g['calls']);
        }

        return $all;
    }

    private function date(?string $value, bool $endOfDay): ?Carbon
    {
        if (! $value || ! strtotime($value)) {
            return null;
        }
        $d = Carbon::parse($value);

        return $endOfDay ? $d->endOfDay() : $d->startOfDay();
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /** @return list<list<string|int>> rows for the CSV export: journeys first, then single calls. */
    public function csvRows(array $data): array
    {
        $rows = [];
        $row = fn (string $journey, string $status, int $n, array $s) => [
            $journey, $status, $n ?: '', $s['label'], $s['product'], $s['operation'], (string) $s['plan_id'], (string) $s['scope'],
            (string) $s['transaction_id'], (string) $s['related_transaction_id'], $s['at']->toIso8601String(), (string) $s['response_type'],
            (string) $s['result'], (string) $s['directory_id'], (string) $s['service_id'], (string) $s['avc_id'], (string) $s['environment'], $s['source'],
        ];

        foreach ($data['journeys'] as $j) {
            foreach ($j['steps'] as $n => $s) {
                $rows[] = $row($j['id'] . ' ' . $j['title'], $j['complete'] ? 'complete' : 'incomplete: missing ' . implode(', ', $j['missing']), $n + 1, $s);
            }
        }
        foreach ($data['singles'] as $g) {
            foreach ($g['calls'] as $s) {
                $rows[] = $row('', 'single call', 0, $s);
            }
        }

        return $rows;
    }
}
