<?php

namespace App\Services\Monitoring;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Domain;
use App\Models\InternetService;
use App\Models\IntegrationRequest;
use App\Models\SyncRun;
use App\Support\Redactor;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind Admin > Audit Logs. Every tab is server-side paginated and searched (tables can be large;
 * the queries use the (direction, created_at) / (is_error, created_at) / audit_logs indexes), and returns plain
 * arrays the page renders with textContent only.
 *
 * Tabs: activity | outbound | inbound | errors | jobs
 */
class MonitoringFeed
{
    public const TABS = ['activity', 'outbound', 'inbound', 'errors', 'jobs'];
    public const PER_PAGE = 50;

    private const MODULE_LABELS = [
        'halo' => 'HaloPSA', 'itglue' => 'IT Glue', 'synergy' => 'Synergy Wholesale', 'vocus' => 'Vocus WSM', 'ip2whois' => 'IP2WHOIS',
        'api' => 'API', 'auth' => 'Authentication', 'sync' => 'Sync', 'clients' => 'Clients', 'domains' => 'Domains', 'settings' => 'Settings',
        'users' => 'Users', 'account' => 'Account', 'platform' => 'Platform', 'notifications' => 'Notifications', 'monitoring' => 'Audit Logs',
        'queue' => 'Queue', 'scheduler' => 'Scheduler', 'artisan' => 'Console', 'https' => 'Platform HTTPS',
    ];

    public static function isTab(string $tab): bool
    {
        return in_array($tab, self::TABS, true);
    }

    public static function moduleLabel(?string $slug): string
    {
        $slug = (string) $slug;

        return self::MODULE_LABELS[$slug] ?? ($slug === '' ? 'App' : ucfirst($slug));
    }

    // ------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------

    /**
     * @param array{q?:string,status?:string,provider?:string,entity?:string,from?:string,to?:string,debug?:bool,system?:bool,group?:bool,fp?:array} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int, from: int, to: int, last_page: int}
     */
    public function page(string $tab, array $filters, int $page): array
    {
        $page = max(1, $page);

        [$total, $rows] = match ($tab) {
            'activity' => $this->activity($filters, $page),
            'outbound' => $this->requests(IntegrationRequest::OUTBOUND, $filters, $page),
            'inbound' => $this->requests(IntegrationRequest::INBOUND, $filters, $page),
            'errors' => $this->errors($filters, $page),
            'jobs' => $this->jobs($filters, $page),
        };

        $count = count($rows);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'from' => $count === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
            'to' => $count === 0 ? 0 : ($page - 1) * self::PER_PAGE + $count,
            'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    private function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    private function dateBounds($query, array $filters, string $column)
    {
        if (! empty($filters['from']) && strtotime((string) $filters['from'])) {
            $query->where($column, '>=', date('Y-m-d 00:00:00', strtotime((string) $filters['from'])));
        }
        if (! empty($filters['to']) && strtotime((string) $filters['to'])) {
            $query->where($column, '<=', date('Y-m-d 23:59:59', strtotime((string) $filters['to'])));
        }

        return $query;
    }

    /** @return array{0:int,1:array} */
    private function activity(array $filters, int $page): array
    {
        $query = AuditLog::query();

        if (empty($filters['debug'])) {
            $query->whereNotIn('action', (array) config('monitoring.noise_actions', []));
        }
        $failedSql = fn ($w) => $w->where('action', 'like', '%failed%')->orWhere('action', 'like', '%denied%')->orWhere('description', 'like', '%failed%')->orWhere('description', 'like', '%(denied)%');
        // Calm by default: what people did, plus anything that failed. Routine successful system entries sit behind "Show system activity".
        if (empty($filters['system'])) {
            $query->where(fn ($w) => $w->whereNotNull('user_id')->orWhere($failedSql));
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = $this->like($q);
            $query->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('user_email', 'like', $like)->orWhere('action', 'like', $like)
                ->orWhere('context->reference', 'like', $like)->orWhere('new_values', 'like', $like));
        }
        if (($filters['provider'] ?? '') !== '') {
            $query->where('context->service', $filters['provider']);
        }
        if (($filters['status'] ?? '') === 'failed') {
            $query->where($failedSql);
        } elseif (($filters['status'] ?? '') === 'ok') {
            $query->whereNot($failedSql);
        }
        $this->dateBounds($query, $filters, 'created_at');

        $total = (clone $query)->count();
        $logs = $query->orderByDesc('id')->forPage($page, self::PER_PAGE)->get();

        return [$total, $logs->map(function (AuditLog $log) {
            $ctx = (array) ($log->context ?? []);

            return [
                'id' => (string) $log->id,
                'when' => $log->created_at?->toIso8601String(),
                'user' => $log->user_email ?: 'System',
                'system' => $log->user_id === null,
                'module' => self::moduleLabel($ctx['service'] ?? strtok($log->action, '.')),
                'description' => (string) ($log->description ?: $log->action),
                'reference' => (string) ($ctx['reference'] ?? ($log->auditable_id ? class_basename((string) $log->auditable_type) . ' #' . $log->auditable_id : '')),
                'status' => $this->activityFailed($log) ? 'failed' : 'success',
            ];
        })->all()];
    }

    private function activityFailed(AuditLog $log): bool
    {
        $ctx = (array) ($log->context ?? []);

        return (bool) preg_match('/(failed|denied)/i', (string) $log->action)
            || (isset($ctx['status_code']) && (int) $ctx['status_code'] >= 400);
    }

    /** Columns that identify "the same request" when repeated calls (polling) are folded into one row. */
    private const GROUP_COLUMNS = ['provider', 'method', 'operation', 'url', 'reference', 'entity_type', 'environment', 'product_id', 'scope', 'is_error', 'response_status'];

    /** @return array{0:int,1:array} */
    private function requests(string $direction, array $filters, int $page): array
    {
        $query = IntegrationRequest::query()->where('direction', $direction);

        if (($filters['provider'] ?? '') !== '') {
            $query->where('provider', $filters['provider']);
        }
        if (($filters['entity'] ?? '') !== '') {
            $query->where('entity_type', $filters['entity']);
        }
        if (($filters['status'] ?? '') === 'failed') {
            $query->where('is_error', true);
        } elseif (($filters['status'] ?? '') === 'ok') {
            $query->where('is_error', false);
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = $this->like($q);
            $query->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('url', 'like', $like)->orWhere('operation', 'like', $like)
                ->orWhere('error_message', 'like', $like)->orWhere('actor', 'like', $like)
                ->orWhere('transaction_id', 'like', $like)->orWhere('related_transaction_id', 'like', $like)->orWhere('service_id', 'like', $like)
                ->orWhere('directory_id', 'like', $like)->orWhere('avc_id', 'like', $like)->orWhere('product_id', 'like', $like)->orWhere('scope', 'like', $like)
                ->orWhere('id', ctype_digit($q) ? (int) $q : 0));
        }
        $this->dateBounds($query, $filters, 'created_at');

        // Expanding a folded row: exactly the calls that share its fingerprint.
        $fp = is_array($filters['fp'] ?? null) ? $filters['fp'] : null;
        if ($fp !== null) {
            foreach (self::GROUP_COLUMNS as $col) {
                if (! array_key_exists($col, $fp)) {
                    continue;
                }
                $fp[$col] === null ? $query->whereNull($col) : $query->where($col, $fp[$col]);
            }
            if (! empty($fp['day']) && is_string($fp['day'])) {
                $query->whereRaw('DATE(created_at) = ?', [substr($fp['day'], 0, 10)]);
            }
        }

        $grouped = $fp === null && ($filters['group'] ?? true);
        $cols = ['id', 'created_at', 'provider', 'method', 'operation', 'url', 'reference', 'entity_type', 'environment', 'product_id', 'scope',
            'transaction_id', 'result', 'response_status', 'duration_ms', 'is_error', 'actor', 'ip'];

        if ($grouped) {
            $folded = (clone $query)->toBase()->selectRaw(
                'MAX(id) as id, COUNT(*) as cnt, MIN(created_at) as first_at, MAX(created_at) as last_at, MAX(transaction_id) as transaction_id, MAX(duration_ms) as duration_ms, MAX(actor) as actor, MAX(ip) as ip, MAX(result) as result, DATE(created_at) as day, '
                . implode(', ', self::GROUP_COLUMNS)
            )->groupBy(...self::GROUP_COLUMNS)->groupByRaw('DATE(created_at)');

            $total = (int) DB::query()->fromSub($folded, 'g')->count();
            $rows = DB::query()->fromSub($folded, 'g')->orderByDesc('id')->forPage($page, self::PER_PAGE)->get()
                ->map(fn ($r) => (object) (array) $r);
        } else {
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('id')->forPage($page, self::PER_PAGE)->get($cols)->map(function (IntegrationRequest $r) {
                $a = $r->getAttributes();
                $a['cnt'] = 1;
                $a['last_at'] = $r->created_at;
                $a['first_at'] = $r->created_at;
                $a['day'] = $r->created_at?->toDateString();

                return (object) $a;
            });
        }

        return [$total, $rows->map(function ($r) use ($grouped) {
            $count = (int) $r->cnt;
            $model = new IntegrationRequest((array) $r);
            $entity = $r->entity_type ? str_replace('_', ' ', (string) $r->entity_type) : '';

            return [
                'id' => (string) $r->id,
                'when' => $r->last_at ? \Illuminate\Support\Carbon::parse($r->last_at)->toIso8601String() : null,
                'first_when' => $count > 1 && $r->first_at ? \Illuminate\Support\Carbon::parse($r->first_at)->toIso8601String() : null,
                'provider' => IntegrationRequest::providerLabel($r->provider),
                'provider_key' => (string) $r->provider,
                'method' => (string) $r->method,
                'description' => $this->describeRequest($model),
                'entity' => $entity,
                'reference' => (string) $r->reference,
                'environment' => (string) ($r->environment ?? ''),
                'transaction_id' => (string) ($r->transaction_id ?? ''),
                'actor' => (string) $r->actor,
                'ip' => (string) $r->ip,
                'status_code' => $r->response_status,
                'result' => (string) ($r->result ?? ''),
                'duration_ms' => $r->duration_ms,
                'count' => $count,
                'fp' => $grouped && $count > 1 ? array_merge(array_intersect_key((array) $r, array_flip(self::GROUP_COLUMNS)), ['day' => (string) $r->day]) : null,
                'status' => $r->is_error ? 'failed' : 'success',
            ];
        })->all()];
    }

    /** One short line: SOAP "PRODUCT::Operation SCOPE", otherwise "METHOD /path". */
    private function describeRequest(IntegrationRequest $r): string
    {
        if ($r->method === 'SOAP' && $r->product_id) {
            return trim($r->product_id . '::' . $r->operation . ' ' . $r->scope);
        }
        if ($r->operation && $r->method === 'SOAP') {
            return (string) $r->operation;
        }

        $parts = parse_url((string) $r->url);
        $path = ($parts['path'] ?? (string) $r->url) . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return mb_substr($path, 0, 90);
    }

    /**
     * "Find by transaction ID / service / domain": hit counts per log for one search text, so the page can jump to the right tab.
     *
     * @return array{q: string, counts: array<string,int>, best: ?string}
     */
    public function find(string $q): array
    {
        $q = mb_substr(trim($q), 0, 120);
        $counts = [];
        if ($q !== '') {
            foreach (['outbound', 'activity', 'errors', 'inbound'] as $tab) {
                $counts[$tab] = $this->page($tab, ['q' => $q, 'system' => true, 'group' => false], 1)['total'];
            }
        }
        $best = null;
        foreach ($counts as $tab => $n) {
            if ($n > 0) {
                $best = $tab;
                break;
            }
        }

        return ['q' => $q, 'counts' => $counts, 'best' => $best];
    }

    /** @return array{0:int,1:array} */
    private function errors(array $filters, int $page): array
    {
        $parts = [
            DB::table('integration_requests')
                ->where('direction', IntegrationRequest::OUTBOUND)->where('is_error', true)
                ->selectRaw("'r' as src, id as rid, created_at as occurred_at, provider as module, entity_type as entity, reference as reference, error_message as error, transaction_id as txn"),
            DB::table('domains')->whereNotNull('halo_sync_error')->where('halo_sync_error', '!=', '')
                ->selectRaw("'dh' as src, id as rid, updated_at as occurred_at, 'halo' as module, 'domain' as entity, name as reference, halo_sync_error as error, NULL as txn"),
            DB::table('domains')->whereNotNull('itglue_sync_error')->where('itglue_sync_error', '!=', '')
                ->selectRaw("'di' as src, id as rid, updated_at as occurred_at, 'itglue' as module, 'domain' as entity, name as reference, itglue_sync_error as error, NULL as txn"),
            DB::table('internet_services')->whereNotNull('halo_sync_error')->where('halo_sync_error', '!=', '')
                ->selectRaw("'ih' as src, id as rid, updated_at as occurred_at, 'halo' as module, 'internet_service' as entity, vocus_service_id as reference, halo_sync_error as error, NULL as txn"),
        ];

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $query = DB::query()->fromSub($union, 'e');

        if (($filters['provider'] ?? '') !== '') {
            $query->where('module', $filters['provider']);
        }
        if (($filters['entity'] ?? '') !== '') {
            $query->where('entity', $filters['entity']);
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = $this->like($q);
            $query->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('error', 'like', $like)->orWhere('entity', 'like', $like)->orWhere('txn', 'like', $like));
        }
        $this->dateBounds($query, $filters, 'occurred_at');

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('occurred_at')->orderByDesc('rid')->forPage($page, self::PER_PAGE)->get();

        return [$total, $rows->map(fn ($r) => [
            'id' => $r->src . ':' . $r->rid,
            'when' => $r->occurred_at ? \Illuminate\Support\Carbon::parse($r->occurred_at)->toIso8601String() : null,
            'module' => self::moduleLabel($r->module),
            'entity' => str_replace('_', ' ', (string) $r->entity),
            'reference' => (string) $r->reference,
            'transaction_id' => (string) ($r->txn ?? ''),
            'error' => mb_substr((string) $r->error, 0, 300),
            'status' => 'failed',
        ])->all()];
    }

    /** @return array{0:int,1:array} */
    private function jobs(array $filters, int $page): array
    {
        $query = SyncRun::query();

        if (($filters['provider'] ?? '') !== '') {
            $query->where('provider', $filters['provider']);
        }
        if (($status = (string) ($filters['status'] ?? '')) !== '') {
            match ($status) {
                'failed' => $query->whereIn('status', [SyncRun::FAILED, SyncRun::PARTIAL]),
                'ok' => $query->whereIn('status', [SyncRun::OK, SyncRun::SKIPPED]),
                'active' => $query->whereIn('status', [SyncRun::QUEUED, SyncRun::RUNNING]),
                default => $query->where('status', $status),
            };
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = $this->like($q);
            $query->where(fn ($w) => $w->where('scope', 'like', $like)->orWhere('provider', 'like', $like)->orWhere('error', 'like', $like)->orWhere('id', ctype_digit($q) ? (int) $q : 0));
        }
        $this->dateBounds($query, $filters, 'created_at');

        $total = (clone $query)->count();
        $runs = $query->orderByDesc('id')->forPage($page, self::PER_PAGE)->get();
        $clients = Client::query()->whereIn('id', $runs->pluck('client_id')->filter()->unique())->pluck('business_name', 'id');

        return [$total, $runs->map(fn (SyncRun $run) => [
            'id' => (string) $run->id,
            'when' => ($run->started_at ?? $run->created_at)?->toIso8601String(),
            'provider' => IntegrationRequest::providerLabel($run->provider),
            'scope' => $run->scope,
            'reference' => $run->client_id ? (string) ($clients[$run->client_id] ?? 'Client #' . $run->client_id) : 'All clients',
            'run_status' => $run->status,
            'counts' => sprintf('%d done, %d failed, %d skipped of %d', $run->done, $run->failed, $run->skipped, $run->total),
            'duration' => $run->started_at && $run->finished_at ? (int) max(0, $run->finished_at->diffInSeconds($run->started_at, true)) : null,
            'error' => mb_substr((string) $run->error, 0, 300),
            'status' => in_array($run->status, [SyncRun::FAILED, SyncRun::PARTIAL], true) ? 'failed' : (in_array($run->status, [SyncRun::QUEUED, SyncRun::RUNNING], true) ? 'active' : 'success'),
        ])->all()];
    }

    // ------------------------------------------------------------------
    // Detail
    // ------------------------------------------------------------------

    /**
     * Detail modal payload: title, status tick, tabs of typed blocks (kv | text | code | headers | diff | lines).
     * Returns null when the entry does not exist.
     */
    public function detail(string $tab, string $id): ?array
    {
        return match ($tab) {
            'activity' => ctype_digit($id) && ($log = AuditLog::find((int) $id)) ? $this->activityDetail($log) : null,
            'outbound', 'inbound' => ctype_digit($id) && ($r = IntegrationRequest::find((int) $id)) ? $this->requestDetail($r, $tab) : null,
            'errors' => $this->errorDetail($id),
            'jobs' => ctype_digit($id) && ($run = SyncRun::find((int) $id)) ? $this->jobDetail($run) : null,
            default => null,
        };
    }

    private function requestDetail(IntegrationRequest $r, string $tab): array
    {
        $status = $r->response_status;
        $variant = $status === null ? 'danger' : ($status >= 500 ? 'danger' : ($status >= 400 ? 'warning' : 'success'));
        $path = $this->describeRequest($r);
        $inbound = $r->direction === IntegrationRequest::INBOUND;

        $requestBlocks = [
            ['label' => 'Timestamp', 'type' => 'time', 'value' => $r->created_at?->toIso8601String()],
            ['label' => $r->method === 'SOAP' ? 'Operation' : 'Method', 'type' => 'text', 'value' => $r->method === 'SOAP' ? (string) $r->operation : (string) $r->method],
            ['label' => $r->method === 'SOAP' ? 'Endpoint' : 'URL', 'type' => 'text', 'value' => (string) $r->url],
            ['label' => 'Headers', 'type' => 'headers', 'value' => $this->recheckHeaders((array) ($r->request_headers ?? []))],
            ['label' => 'Payload' . ($r->request_truncated ? ' (truncated)' : ''), 'type' => 'code', 'value' => $this->safeText($r->request_body)],
        ];

        $responseBlocks = [
            ['label' => 'Status', 'type' => 'text', 'value' => $status !== null ? (string) $status : 'No response (connection failed)'],
            ['label' => 'Duration', 'type' => 'text', 'value' => $r->duration_ms !== null ? $r->duration_ms . ' ms' : ''],
            ['label' => 'Headers', 'type' => 'headers', 'value' => Redactor::filterResponseHeaders((array) ($r->response_headers ?? []))],
            ['label' => 'Body' . ($r->response_truncated ? ' (truncated)' : ''), 'type' => 'code', 'value' => $this->safeText($r->response_body)],
        ];
        if ($r->is_error && $r->error_message) {
            array_unshift($responseBlocks, ['label' => 'Error', 'type' => 'text', 'value' => $this->safeText($r->error_message)]);
        }

        $meta = array_values(array_filter([
            $r->reference ? ['label' => 'Reference', 'value' => (string) $r->reference] : null,
            $r->entity_type ? ['label' => 'Entity', 'value' => str_replace('_', ' ', (string) $r->entity_type)] : null,
            $inbound && $r->actor ? ['label' => 'API key', 'value' => (string) $r->actor] : null,
            $inbound && $r->ip ? ['label' => 'IP address', 'value' => (string) $r->ip] : null,
            $r->sync_run_id ? ['label' => 'Sync run', 'value' => '#' . $r->sync_run_id] : null,
            $r->environment ? ['label' => 'Environment', 'value' => (string) $r->environment] : null,
            $r->transaction_id ? ['label' => 'TransactionID', 'value' => (string) $r->transaction_id] : null,
            $r->related_transaction_id ? ['label' => 'Polled transaction', 'value' => (string) $r->related_transaction_id] : null,
            $r->response_type ? ['label' => 'Response type', 'value' => (string) $r->response_type] : null,
            $r->result ? ['label' => 'Result', 'value' => (string) $r->result] : null,
            $r->plan_id ? ['label' => 'PlanID', 'value' => (string) $r->plan_id] : null,
            $r->directory_id ? ['label' => 'DirectoryID', 'value' => (string) $r->directory_id] : null,
            $r->service_id ? ['label' => 'ServiceID', 'value' => (string) $r->service_id] : null,
            $r->avc_id ? ['label' => 'AVCID', 'value' => (string) $r->avc_id] : null,
        ]));

        return [
            'id' => (string) $r->id,
            'tab' => $tab,
            'title' => $inbound
                ? trim($r->method . ' ' . $path)
                : IntegrationRequest::providerLabel($r->provider) . ' ' . ($r->method === 'SOAP' ? $path : trim($r->method . ' ' . $path)),
            'status' => $r->is_error ? 'failed' : 'success',
            'meta' => $meta,
            'deletable' => true,
            'tabs' => [
                ['key' => 'request', 'label' => 'Request', 'blocks' => $requestBlocks],
                ['key' => 'response', 'label' => 'Response', 'badge' => ['text' => $status !== null ? (string) $status : 'Failed', 'variant' => $variant], 'blocks' => $responseBlocks],
            ],
        ];
    }

    private function activityDetail(AuditLog $log): array
    {
        $ctx = (array) ($log->context ?? []);
        $old = (array) ($log->old_values ?? []);
        $new = (array) ($log->new_values ?? []);
        $payload = Arr::pull($new, 'payload');

        $diff = [];
        $flatOld = Arr::dot(Redactor::redact($old));
        $flatNew = Arr::dot(Redactor::redact($new));
        foreach (array_unique(array_merge(array_keys($flatOld), array_keys($flatNew))) as $field) {
            $o = $flatOld[$field] ?? null;
            $n = $flatNew[$field] ?? null;
            if ($o === $n) {
                continue;
            }
            $diff[] = ['field' => (string) $field, 'old' => $this->scalarText($o), 'new' => $this->scalarText($n)];
            if (count($diff) >= 200) {
                break;
            }
        }

        $tabs = [[
            'key' => 'details', 'label' => 'Details', 'blocks' => array_values(array_filter([
                ['label' => 'Timestamp', 'type' => 'time', 'value' => $log->created_at?->toIso8601String()],
                ['label' => 'Description', 'type' => 'text', 'value' => (string) $log->description],
                ['label' => 'Event', 'type' => 'text', 'value' => (string) $log->action],
                ['label' => 'User', 'type' => 'text', 'value' => (string) ($log->user_email ?: '-')],
                ['label' => 'Module', 'type' => 'text', 'value' => self::moduleLabel($ctx['service'] ?? strtok($log->action, '.'))],
                ['label' => 'Reference', 'type' => 'text', 'value' => (string) ($ctx['reference'] ?? ($log->auditable_id ? class_basename((string) $log->auditable_type) . ' #' . $log->auditable_id : ''))],
                ! empty($ctx['path']) ? ['label' => 'Request', 'type' => 'text', 'value' => trim(($ctx['method'] ?? '') . ' ' . $ctx['path'])] : null,
                isset($ctx['status_code']) ? ['label' => 'HTTP status', 'type' => 'text', 'value' => (string) $ctx['status_code']] : null,
                ['label' => 'IP address', 'type' => 'text', 'value' => (string) $log->ip_address],
                ['label' => 'User agent', 'type' => 'text', 'value' => mb_substr((string) $log->user_agent, 0, 300)],
                ! empty($ctx['impersonator_id']) ? ['label' => 'Impersonated by (user id)', 'type' => 'text', 'value' => (string) $ctx['impersonator_id']] : null,
            ])),
        ], [
            'key' => 'changes', 'label' => 'Changes', 'badge' => $diff ? ['text' => (string) count($diff), 'variant' => 'info'] : null,
            'blocks' => [['label' => 'Changed fields (old to new)', 'type' => 'diff', 'value' => $diff]],
        ]];

        $extra = array_filter(['Request payload' => $payload, 'Details' => array_diff_key($ctx, array_flip(['function', 'service', 'automated', 'method', 'path', 'route_name', 'status_code', 'reference', 'impersonator_id'])) ?: null]);
        if ($extra) {
            $tabs[] = ['key' => 'request', 'label' => 'Request', 'blocks' => collect($extra)->map(fn ($v, $label) => [
                'label' => $label, 'type' => 'code',
                'value' => $this->safeText((string) json_encode(Redactor::redact((array) $v), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)),
            ])->values()->all()];
        }

        return [
            'id' => (string) $log->id,
            'tab' => 'activity',
            'title' => (string) ($log->description ?: $log->action),
            'status' => $this->activityFailed($log) ? 'failed' : 'success',
            'meta' => [],
            // The audit trail is append-only from the UI: rows leave only through retention pruning.
            'deletable' => false,
            'tabs' => $tabs,
        ];
    }

    private function jobDetail(SyncRun $run): array
    {
        $related = IntegrationRequest::query()->where('sync_run_id', $run->id)->orderBy('id')->limit(50)
            ->get(['id', 'provider', 'method', 'operation', 'url', 'response_status', 'duration_ms', 'is_error', 'reference']);
        $relatedTotal = IntegrationRequest::query()->where('sync_run_id', $run->id)->count();
        $clientName = $run->client_id ? (Client::find($run->client_id)?->business_name ?? 'Client #' . $run->client_id) : 'All clients';
        $failed = in_array($run->status, [SyncRun::FAILED, SyncRun::PARTIAL], true);

        return [
            'id' => (string) $run->id,
            'tab' => 'jobs',
            'title' => IntegrationRequest::providerLabel($run->provider) . ' ' . $run->scope . ' sync #' . $run->id,
            'status' => $failed ? 'failed' : ($run->isFinished() ? 'success' : 'active'),
            'meta' => [],
            'deletable' => $run->isFinished(),
            'tabs' => [
                ['key' => 'run', 'label' => 'Run', 'badge' => ['text' => ucfirst($run->status), 'variant' => $failed ? 'danger' : ($run->isFinished() ? 'success' : 'info')], 'blocks' => array_values(array_filter([
                    ['label' => 'Started', 'type' => 'time', 'value' => ($run->started_at ?? $run->created_at)?->toIso8601String()],
                    ['label' => 'Finished', 'type' => 'time', 'value' => $run->finished_at?->toIso8601String()],
                    ['label' => 'Provider', 'type' => 'text', 'value' => IntegrationRequest::providerLabel($run->provider)],
                    ['label' => 'Scope', 'type' => 'text', 'value' => $run->scope],
                    ['label' => 'Client', 'type' => 'text', 'value' => $clientName],
                    ['label' => 'Items', 'type' => 'text', 'value' => sprintf('%d done, %d failed, %d skipped of %d', $run->done, $run->failed, $run->skipped, $run->total)],
                    $run->error ? ['label' => 'Error', 'type' => 'text', 'value' => $this->safeText($run->error)] : null,
                ]))],
                ['key' => 'requests', 'label' => 'Requests', 'badge' => $relatedTotal ? ['text' => (string) $relatedTotal, 'variant' => 'info'] : null, 'blocks' => [[
                    'label' => 'Integration requests recorded for this run' . ($relatedTotal > 50 ? ' (first 50)' : ''),
                    'type' => 'lines',
                    'value' => $related->map(fn (IntegrationRequest $r) => trim(sprintf(
                        '#%d  %s  %s  %s  %s%s',
                        $r->id, IntegrationRequest::providerLabel($r->provider), $r->method === 'SOAP' ? $r->operation : $r->method . ' ' . $this->describeRequest($r),
                        $r->response_status ?? 'no response', $r->duration_ms !== null ? $r->duration_ms . ' ms' : '', $r->is_error ? '  [error]' : ''
                    )))->all(),
                ]]],
            ],
        ];
    }

    private function errorDetail(string $id): ?array
    {
        [$src, $rid] = array_pad(explode(':', $id, 2), 2, '');
        if (! ctype_digit($rid)) {
            return null;
        }

        if ($src === 'r') {
            $r = IntegrationRequest::find((int) $rid);

            return $r ? array_replace($this->requestDetail($r, 'errors'), ['id' => $id]) : null;
        }

        [$model, $module, $entity, $labelField, $errorField] = match ($src) {
            'dh' => [Domain::class, 'halo', 'domain', 'name', 'halo_sync_error'],
            'di' => [Domain::class, 'itglue', 'domain', 'name', 'itglue_sync_error'],
            'ih' => [InternetService::class, 'halo', 'internet service', 'vocus_service_id', 'halo_sync_error'],
            default => [null, null, null, null, null],
        };
        if ($model === null || ! ($record = $model::find((int) $rid))) {
            return null;
        }

        return [
            'id' => $id,
            'tab' => 'errors',
            'title' => self::moduleLabel($module) . ' sync error: ' . $record->{$labelField},
            'status' => 'failed',
            'meta' => [],
            'deletable' => false,
            'tabs' => [['key' => 'error', 'label' => 'Error', 'blocks' => [
                ['label' => 'Last updated', 'type' => 'time', 'value' => $record->updated_at?->toIso8601String()],
                ['label' => 'Module', 'type' => 'text', 'value' => self::moduleLabel($module)],
                ['label' => 'Entity', 'type' => 'text', 'value' => $entity],
                ['label' => 'Reference', 'type' => 'text', 'value' => (string) $record->{$labelField}],
                ['label' => 'Error', 'type' => 'text', 'value' => $this->safeText((string) $record->{$errorField})],
                ['label' => 'Note', 'type' => 'text', 'value' => 'This error is stored on the record and clears itself after the next successful sync of it.'],
            ]]],
        ];
    }

    // ------------------------------------------------------------------
    // Export / delete
    // ------------------------------------------------------------------

    /** Detail payload plus raw (already redacted) record fields, for the "Export to JSON" download. */
    public function export(string $tab, string $id): ?array
    {
        $detail = $this->detail($tab, $id);
        if ($detail === null) {
            return null;
        }

        $record = null;
        if (in_array($tab, ['outbound', 'inbound'], true) || ($tab === 'errors' && str_starts_with($id, 'r:'))) {
            $record = IntegrationRequest::find((int) substr($id, strrpos(':' . $id, ':')))?->toArray();
        } elseif ($tab === 'activity') {
            $record = AuditLog::find((int) $id)?->toArray();
        } elseif ($tab === 'jobs') {
            $record = SyncRun::find((int) $id)?->toArray();
        }

        return [
            'exported_at' => now()->toIso8601String(),
            'source' => $tab,
            'title' => $detail['title'],
            'status' => $detail['status'],
            'record' => is_array($record) ? Redactor::redact($record) : null,
            'detail' => $detail['tabs'],
        ];
    }

    /** @return array{ok: bool, summary: string}|null null when the entry cannot be deleted / does not exist */
    public function delete(string $tab, string $id): ?array
    {
        if ($tab === 'activity' || ($tab === 'errors' && ! str_starts_with($id, 'r:'))) {
            return null;
        }

        if ($tab === 'jobs') {
            $run = ctype_digit($id) ? SyncRun::find((int) $id) : null;
            if (! $run || ! $run->isFinished()) {
                return null;
            }
            $summary = IntegrationRequest::providerLabel($run->provider) . " {$run->scope} sync #{$run->id} ({$run->status})";
            $run->delete();

            return ['ok' => true, 'summary' => $summary];
        }

        $rid = $tab === 'errors' ? substr($id, 2) : $id;
        $r = ctype_digit($rid) ? IntegrationRequest::find((int) $rid) : null;
        if (! $r) {
            return null;
        }
        $summary = trim(IntegrationRequest::providerLabel($r->provider) . ' ' . ($r->method === 'SOAP' ? $r->operation : $r->method . ' ' . $this->describeRequest($r)) . ' (#' . $r->id . ')');
        $r->delete();

        return ['ok' => true, 'summary' => $summary];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Defence in depth: stored text is redacted already; run the text redactor once more before it leaves the server. */
    private function safeText(?string $text): string
    {
        return $text === null || $text === '' ? '' : Redactor::redactText($text);
    }

    /** Stored headers are masked at write time; re-mask any credential that is not already masked (defence in depth). */
    private function recheckHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            $already = str_contains($value, Redactor::MASK) || str_ends_with($value, '…');
            $out[$name] = $already ? $value : Redactor::maskHeaders([$name => $value])[$name];
        }

        return $out;
    }

    private function scalarText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? mb_substr((string) $value, 0, 500) : mb_substr((string) json_encode($value), 0, 500);
    }
}
