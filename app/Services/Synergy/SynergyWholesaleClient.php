<?php

namespace App\Services\Synergy;

use SoapClient;
use App\Models\Setting;

class SynergyWholesaleClient
{
    protected SoapClient $soap;

    public function __construct(?string $wsdlPath = null)
    {
        // All Synergy config is stored under a single "synergy" setting.
        $synergy = Setting::get('synergy', [
            'reseller_id' => null,
            'api_key'     => null,
            'wsdl_path'   => null,
        ]);

        $wsdl = $wsdlPath
            ?: ($synergy['wsdl_path'] ?? storage_path('app/wsdl/synergy.wsdl'));

        $options = [
            'trace'      => 1,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
        ];

        $this->soap = new SoapClient($wsdl, $options);
    }

    protected function creds(): array
    {
        $synergy = Setting::get('synergy', [
            'reseller_id' => null,
            'api_key'     => null,
        ]);

        return [
            'resellerID' => $synergy['reseller_id'] ?? null,
            'apiKey'     => $synergy['api_key'] ?? null,
        ];
    }

    /**
     * Update nameservers and DNS config for a domain.
     *
     * Wraps the Synergy "updateNameServers" SOAP operation.
     *
     * WSDL: updateNameServersRequest:
     *   resellerID, apiKey, domainName,
     *   nameServers (tns:nameServerRequest),
     *   dnsConfigType (xsd:int, nillable, minOccurs=0),
     *   dnsConfig (xsd:int, nillable, minOccurs=0).
     *
     * We model nameServerRequest very simply as up to 4 hostnames.
     */
    public function updateNameServers(
        string $domainName,
        array $nameservers = [],
        ?int $dnsConfig = null,
        ?int $dnsConfigType = null
    ): array {
        // Strip empties
        $nameservers = array_values(array_filter($nameservers));

        // For a SOAP-ENC:Array, a simple numeric array of hostnames is fine.
        $params = array_merge($this->creds(), [
            'domainName' => $domainName,
            'nameServers'=> $nameservers,
        ]);

        if ($dnsConfig !== null) {
            $params['dnsConfig'] = $dnsConfig;
        }

        if ($dnsConfigType !== null) {
            $params['dnsConfigType'] = $dnsConfigType;
        }

        $res = $this->soap->__soapCall('updateNameServers', [$params]);

        return (array) $res;
    }

    /* -----------------------------------------------------------------
     |  Domains – info / auth code
     |------------------------------------------------------------------*/

    /**
     * Single domain info (wraps `domainInfo`).
     */
    public function domainInfo(string $domainName): array
    {
        $params = array_merge($this->creds(), [
            'domainName' => $domainName,
        ]);

        $res = $this->soap->__soapCall('domainInfo', [$params]);

        return (array) $res;
    }

    /**
     * Convenience helper that just returns the EPP/auth code string,
     * or null if none is present.
     */
    public function getDomainPassword(string $domainName): ?string
    {
        $info = $this->domainInfo($domainName);

        // Field name taken directly from the WSDL: <domainPassword>
        return $info['domainPassword'] ?? null;
    }

    /* -----------------------------------------------------------------
     |  DNS – zone + records
     |------------------------------------------------------------------*/

    /** DNS Zone list */
    public function listDNSZone(string $domain): array
    {
        $params = array_merge($this->creds(), [
            'domainName' => $domain,
        ]);

        $res = $this->soap->__soapCall('listDNSZone', [$params]);

        // WSDL: listDNSZoneResponse.records (tns:listDNSZoneArray)
        $records = $res->records ?? [];

        // Normalise – SOAP returns a single stdClass when there’s only one record
        if (is_object($records)) {
            $records = [$records];
        }

        if (! is_array($records)) {
            $records = [];
        }

        return $records;
    }

    public function addDNSRecord(
        string $domainName,
        string $host,
        string $type,
        string $content,
        int $ttl = 3600,
        int $prio = 0
    ): array {
        $params = array_merge($this->creds(), [
            'domainName'    => $domainName,
            'recordName'    => $host,
            'recordType'    => $type,
            'recordContent' => $content,
            'recordTTL'     => $ttl,
            'recordPrio'    => $prio,
        ]);

        $res = $this->soap->__soapCall('addDNSRecord', [$params]);
        return (array) $res;
    }

    public function updateDNSRecord(
        string $domainName,
        string $recordId,
        string $host,
        string $type,
        string $content,
        int $ttl = 3600,
        int $prio = 0
    ): array {
        $params = array_merge($this->creds(), [
            'domainName'    => $domainName,
            'recordID'      => $recordId,
            'recordName'    => $host,
            'recordType'    => $type,
            'recordContent' => $content,
            'recordTTL'     => $ttl,
            'recordPrio'    => $prio,
        ]);

        $res = $this->soap->__soapCall('updateDNSRecord', [$params]);
        return (array) $res;
    }

    public function deleteDNSRecord(string $domainName, string $recordId): array
    {
        $params = array_merge($this->creds(), [
            'domainName' => $domainName,
            'recordID'   => $recordId,
        ]);

        $res = $this->soap->__soapCall('deleteDNSRecord', [$params]);
        return (array) $res;
    }
}
