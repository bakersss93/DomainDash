<?php

namespace Tests\Unit;

use App\Support\AssetFieldSuggester;
use PHPUnit\Framework\TestCase;

class AssetFieldSuggesterTest extends TestCase
{
    public function test_halo_suggests_by_case_insensitive_contains_and_claims_each_field_once(): void
    {
        $fields = [
            ['id' => 10, 'name' => 'DOMAIN NAME'],
            ['id' => 11, 'name' => 'Domain Expiry Date'],
            ['id' => 12, 'name' => 'Primary Nameservers'],
            ['id' => 13, 'name' => 'WHOIS Details'],
            ['id' => 14, 'name' => 'Notes'],
        ];

        $this->assertSame(
            ['domain' => 10, 'expiry' => 11, 'nameservers' => 12, 'whois' => 13],
            AssetFieldSuggester::halo($fields)
        );
    }

    public function test_halo_matches_name_server_spellings_registrant_and_standalone_ns(): void
    {
        $this->assertSame(12, AssetFieldSuggester::halo([['id' => 12, 'name' => 'Name Server List']])['nameservers']);
        $this->assertSame(13, AssetFieldSuggester::halo([['id' => 13, 'name' => 'Registrant']])['whois']);
        $this->assertSame(14, AssetFieldSuggester::halo([['id' => 14, 'name' => 'NS']])['nameservers']);
        // "ns" inside another word must not match
        $this->assertNull(AssetFieldSuggester::halo([['id' => 15, 'name' => 'Transfer status']])['nameservers']);
    }

    public function test_halo_returns_null_when_nothing_matches(): void
    {
        $this->assertSame(
            ['domain' => null, 'expiry' => null, 'nameservers' => null, 'whois' => null],
            AssetFieldSuggester::halo([['id' => 1, 'name' => 'Colour'], ['id' => 2, 'name' => 'Weight']])
        );
    }

    public function test_itglue_respects_field_kind(): void
    {
        $fields = [
            ['name' => 'Domain Name', 'name_key' => 'domain-name', 'kind' => 'Text'],
            ['name' => 'Expiry', 'name_key' => 'expiry', 'kind' => 'Date'],
            ['name' => 'Expiry notes', 'name_key' => 'expiry-notes', 'kind' => 'Tag'],
            ['name' => 'Name Servers', 'name_key' => 'name-servers', 'kind' => 'Textbox'],
            ['name' => 'WHOIS', 'name_key' => 'whois', 'kind' => 'Textbox'],
            ['name' => 'DNS Records', 'name_key' => 'dns-records', 'kind' => 'Textbox'],
            ['name' => 'Domain Owner', 'name_key' => 'domain-owner', 'kind' => 'Tag'],
        ];

        $this->assertSame([
            'domain' => 'domain-name',
            'name_servers' => 'name-servers',
            'expiry' => 'expiry',
            'whois' => 'whois',
            'dns' => 'dns-records',
        ], AssetFieldSuggester::itglue($fields));
    }

    public function test_itglue_prefers_date_kind_for_expiry(): void
    {
        $out = AssetFieldSuggester::itglue([
            ['name' => 'Expiry text', 'name_key' => 'expiry-text', 'kind' => 'Text'],
            ['name' => 'Domain expiry date', 'name_key' => 'domain-expiry-date', 'kind' => 'Date'],
        ]);

        $this->assertSame('domain-expiry-date', $out['expiry']);
    }
}
