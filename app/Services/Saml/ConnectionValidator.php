<?php

namespace App\Services\Saml;

use App\Models\SamlConnection;
use App\Models\SamlConnectionDomain;
use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

/**
 * Save-time rules for a connection. Returns [normalised data, errors]. These rules are the first line of
 * defence for tenant isolation; SamlUserProvisioner re-applies the role rules at login time.
 */
class ConnectionValidator
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @return array{0: array, 1: array<string, string>} */
    public function validate(array $in, ?SamlConnection $existing = null): array
    {
        $errors = [];
        $type = SamlConnection::TYPE_STAFF; // SAML is staff-only; any other submitted type is ignored
        $data = [
            'name' => trim((string) ($in['name'] ?? '')),
            'type' => $type,
            'enabled' => (bool) ($in['enabled'] ?? false),
            'entra_tenant_id' => strtolower(trim((string) ($in['entra_tenant_id'] ?? ''))),
            'idp_entity_id' => trim((string) ($in['idp_entity_id'] ?? '')),
            'idp_sso_url' => trim((string) ($in['idp_sso_url'] ?? '')),
            'idp_slo_url' => trim((string) ($in['idp_slo_url'] ?? '')) ?: null,
            'idp_x509_cert' => $this->normaliseCert((string) ($in['idp_x509_cert'] ?? '')),
            'idp_x509_cert_2' => $this->normaliseCert((string) ($in['idp_x509_cert_2'] ?? '')) ?: null,
            'metadata_url' => trim((string) ($in['metadata_url'] ?? '')) ?: null,
            'default_role' => trim((string) ($in['default_role'] ?? '')) ?: null,
            'jit_provisioning' => (bool) ($in['jit_provisioning'] ?? false),
            'skip_local_mfa' => (bool) ($in['skip_local_mfa'] ?? false),
            'group_role_map' => [],
            'domains' => [],
        ];

        if ($data['name'] === '') {
            $errors['name'] = 'A name is required.';
        }

        if (! preg_match(self::GUID, $data['entra_tenant_id'])) {
            $errors['entra_tenant_id'] = 'The Entra tenant ID must be a GUID (Directory ID).';
        }

        if ($data['idp_entity_id'] === '') {
            $errors['idp_entity_id'] = 'The IdP entity ID (Microsoft Entra Identifier) is required.';
        } elseif (preg_match('#^https://sts\.windows\.net/([^/]+)/?$#i', $data['idp_entity_id'], $m)
            && strtolower($m[1]) !== $data['entra_tenant_id']) {
            $errors['idp_entity_id'] = 'The entity ID names a different Entra tenant than the tenant ID above.';
        }

        foreach (['idp_sso_url' => true, 'idp_slo_url' => false] as $field => $required) {
            if ($data[$field] === null || $data[$field] === '') {
                if ($required) {
                    $errors[$field] = 'Required.';
                }
                continue;
            }
            $v = Validator::make([$field => $data[$field]], [$field => [new SafeExternalUrl()]]);
            if ($v->fails()) {
                $errors[$field] = $v->errors()->first($field);
            }
        }

        foreach (['idp_x509_cert' => true, 'idp_x509_cert_2' => false] as $field => $required) {
            if (! $data[$field]) {
                if ($required) {
                    $errors[$field] = 'A signing certificate is required.';
                }
                continue;
            }
            if (! $this->certParses($data[$field])) {
                $errors[$field] = 'This is not a valid X.509 certificate.';
            }
        }

        $domains = $this->parseDomains($in['domains'] ?? '', $errors);
        $data['domains'] = $domains;
        $this->checkDomains($domains, $data, $existing, $errors);

        if ($data['enabled'] && $domains === []) {
            $errors['domains'] = 'An enabled connection needs at least one allowed email domain.';
        }

        [$map, $mapErrors] = $this->parseGroupMap($in['group_role_map'] ?? '');
        $data['group_role_map'] = $map;
        if ($mapErrors) {
            $errors['group_role_map'] = implode(' ', $mapErrors);
        } else {
            $this->checkRoles($map, $data, $errors);
        }

        return [$data, $errors];
    }

    /** @param list<array{group_id:string,role:string}> $map */
    private function checkRoles(array $map, array $data, array &$errors): void
    {
        $roles = collect($map)->pluck('role')->all();
        if ($data['default_role']) {
            $roles[] = $data['default_role'];
        }

        foreach (array_unique($roles) as $role) {
            if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
                $errors['group_role_map'] = "Role \"{$role}\" does not exist.";

                return;
            }
            if (SsoPolicy::isCustomerSafeRole($role)) {
                $field = $role === $data['default_role'] && ! collect($map)->contains('role', $role) ? 'default_role' : 'group_role_map';
                $errors[$field] = "Single sign-on connections can only assign staff roles; \"{$role}\" is a customer role. Clients use Microsoft sign-in.";

                return;
            }
        }
    }

    private function checkDomains(array $domains, array $data, ?SamlConnection $existing, array &$errors): void
    {
        foreach ($domains as $d) {
            $clash = SamlConnectionDomain::query()
                ->where('domain', $d)
                ->when($existing, fn ($q) => $q->where('saml_connection_id', '!=', $existing->id))
                ->exists();
            if ($clash) {
                $errors['domains'] = "The domain {$d} is already claimed by another connection.";

                return;
            }
        }
    }

    /** @return list<string> */
    private function parseDomains(mixed $raw, array &$errors): array
    {
        $items = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($items as $item) {
            $d = strtolower(trim(ltrim(trim((string) $item), '@')));
            if ($d === '') {
                continue;
            }
            if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d)) {
                $errors['domains'] = "\"{$d}\" is not a valid domain name.";
                continue;
            }
            $out[] = $d;
        }

        return array_values(array_unique($out));
    }

    /** One mapping per line: "<group object id> = <role name>". @return array{0: list<array>, 1: list<string>} */
    public function parseGroupMap(mixed $raw): array
    {
        if (is_array($raw)) {
            $lines = array_map(fn ($r) => ($r['group_id'] ?? '').'='.($r['role'] ?? ''), $raw);
        } else {
            $lines = preg_split('/\r?\n/', (string) $raw);
        }

        $map = [];
        $errors = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '=' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s*[=,]\s*/', $line, 2);
            $gid = strtolower(trim($parts[0] ?? ''));
            $role = trim($parts[1] ?? '');
            if (! preg_match(self::GUID, $gid) || $role === '') {
                $errors[] = "Invalid mapping line \"{$line}\" (expected: group-object-id = Role name).";
                continue;
            }
            $map[] = ['group_id' => $gid, 'role' => $role];
        }

        return [$map, $errors];
    }

    /** Strip PEM armour and whitespace; keep the bare base64 body. */
    public function normaliseCert(string $pem): string
    {
        $pem = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $pem);

        return preg_replace('/\s+/', '', (string) $pem);
    }

    public function certParses(string $b64): bool
    {
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($b64, 64, "\n")."-----END CERTIFICATE-----\n";

        return @openssl_x509_parse($pem) !== false;
    }
}
