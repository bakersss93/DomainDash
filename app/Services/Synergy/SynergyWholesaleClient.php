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

        // Normalise – SOAP returns a single stdClass when there's only one record
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

    /* -----------------------------------------------------------------
     |  Hosting Services
     |------------------------------------------------------------------*/

    /**
     * Get hosting service details including password.
     * 
     * @param string $identifier Domain, username, or hoid
     * @param string|null $hoid Optional HOID for more specific lookup
     * @return array
     */
    public function hostingGetService(string $identifier, ?string $hoid = null): array
    {
        $params = array_merge($this->creds(), [
            'identifier' => $identifier,
        ]);

        if ($hoid !== null) {
            $params['hoid'] = $hoid;
        }

        $res = $this->soap->__soapCall('hostingGetService', [$params]);
        return (array) $res;
    }

    /**
     * Get cPanel SSO login URL.
     * 
     * @param string $identifier Domain, username, or hoid
     * @param string|null $hoid Optional HOID for more specific lookup
     * @return array
     */
    public function hostingGetLogin(string $identifier, ?string $hoid = null): array
    {
        $params = array_merge($this->creds(), [
            'identifier' => $identifier,
        ]);

        if ($hoid !== null) {
            $params['hoid'] = $hoid;
        }

        $res = $this->soap->__soapCall('hostingGetLogin', [$params]);
        return (array) $res;
    }

    /**
     * List hosting services with pagination.
     *
     * @param string|null $status Filter by status
     * @param int $page Page number
     * @param int $limit Results per page
     * @return array
     */
    public function listHosting(?string $status = null, int $page = 1, int $limit = 100): array
    {
        $params = array_merge($this->creds(), [
            'page' => $page,
            'limit' => $limit,
        ]);

        if ($status !== null) {
            $params['status'] = $status;
        }

        $res = $this->soap->__soapCall('listHosting', [$params]);
        return (array) $res;
    }

    /**
     * List available hosting packages from Synergy.
     *
     * @return array Response with packages list
     */
    public function hostingListPackages(): array
    {
        $params = $this->creds();
        $res = $this->soap->__soapCall('hostingListPackages', [$params]);
        return (array) $res;
    }

    /* -----------------------------------------------------------------
     |  Domain Registration & Transfer
     |------------------------------------------------------------------*/

    /**
     * Check domain availability.
     *
     * @param string $domainName Full domain name (e.g., example.com.au)
     * @return array Response with availability status
     */
    public function checkDomain(string $domainName): array
    {
        $params = array_merge($this->creds(), [
            'domainName' => $domainName,
        ]);

        $res = $this->soap->__soapCall('checkDomain', [$params]);
        return (array) $res;
    }

    /**
     * Get .au registrant information from ABN/ACN.
     *
     * @param string $idType Type of ID (ABN, ACN, etc.)
     * @param string $idValue The actual ID value
     * @return array Registrant information
     */
    public function auRegistrantInfo(string $idType, string $idValue): array
    {
        $params = array_merge($this->creds(), [
            'idType' => $idType,
            'idNumber' => $idValue,
        ]);

        $res = $this->soap->__soapCall('auRegistrantInfo', [$params]);
        return (array) $res;
    }

    /**
     * Register a new domain.
     *
     * @param string $domainName Domain to register
     * @param int $years Number of years
     * @param array $contacts Contact information
     * @param array $nameservers Array of nameservers
     * @param array $extra Extra parameters (e.g., .au specific fields)
     * @return array Response
     */
    public function registerDomain(
        string $domainName,
        int $years,
        array $contacts,
        array $nameservers = [],
        array $extra = []
    ): array {
        $params = array_merge($this->creds(), [
            'domainName' => $domainName,
            'years' => $years,
        ]);

        // Add contacts
        if (!empty($contacts)) {
            $params = array_merge($params, $contacts);
        }

        // Add nameservers
        if (!empty($nameservers)) {
            $params['nameServers'] = array_values(array_filter($nameservers));
        }

        // Add extra parameters (e.g., for .au domains)
        if (!empty($extra)) {
            $params = array_merge($params, $extra);
        }

        $res = $this->soap->__soapCall('registerDomain', [$params]);
        return (array) $res;
    }

    /**
     * Transfer a domain.
     *
     * @param string $domainName Domain to transfer
     * @param string $authCode EPP/authorization code
     * @param int $years Number of years to renew
     * @param array $contacts Contact information
     * @return array Response
     */
    public function transferDomain(
        string $domainName,
        string $authCode,
        int $years = 1,
        array $contacts = []
    ): array {
        $params = array_merge($this->creds(), [
            'domainName' => $domainName,
            'authCode' => $authCode,
            'years' => $years,
        ]);

        if (!empty($contacts)) {
            $params = array_merge($params, $contacts);
        }

        $res = $this->soap->__soapCall('transferDomain', [$params]);
        return (array) $res;
    }

    /**
     * Renew a domain.
     *
     * @param string $domainName Domain to renew
     * @param int $years Number of years
     * @return array Response
     */
    public function renewDomain(string $domainName, int $years = 1): array
    {
        $params = array_merge($this->creds(), [
            'domainName' => $domainName,
            'years' => $years,
        ]);

        $res = $this->soap->__soapCall('renewDomain', [$params]);
        return (array) $res;
    }

    /**
     * List all domains.
     *
     * @param int $page Page number
     * @param int $limit Results per page
     * @return array List of domains
     */
    public function listDomains(int $page = 1, int $limit = 100): array
    {
        $params = array_merge($this->creds(), [
            'page' => $page,
            'limit' => $limit,
        ]);

        $res = $this->soap->__soapCall('listDomains', [$params]);
        return (array) $res;
    }

    /* -----------------------------------------------------------------
     |  Hosting Purchase
     |------------------------------------------------------------------*/

    /**
     * Purchase hosting service.
     *
     * @param string $planId Package name from hostingListPackages
     * @param string $domain Primary domain for hosting
     * @param string $email Contact email
     * @param array $extra Additional parameters
     * @return array Response with service details
     */
    public function purchaseHosting(
        string $planId,
        string $domain,
        string $email,
        array $extra = []
    ): array {
        $params = array_merge($this->creds(), [
            'planID' => $planId,
            'domain' => $domain,
            'email' => $email,
        ]);

        if (!empty($extra)) {
            $params = array_merge($params, $extra);
        }

        $res = $this->soap->__soapCall('purchaseHosting', [$params]);
        return (array) $res;
    }

    /* -----------------------------------------------------------------
     |  SSL Certificates
     |------------------------------------------------------------------*/

    /**
     * List available SSL products.
     *
     * @return array List of SSL products
     */
    public function listSSLProducts(): array
    {
        $params = $this->creds();
        $res = $this->soap->__soapCall('listSSLProducts', [$params]);
        return (array) $res;
    }

    /**
     * Purchase SSL certificate.
     *
     * @param string $productId SSL product ID
     * @param string $domain Domain for SSL
     * @param int $years Number of years
     * @param array $extra Additional parameters (CSR, etc.)
     * @return array Response
     */
    public function purchaseSSL(
        string $productId,
        string $domain,
        int $years = 1,
        array $extra = []
    ): array {
        $params = array_merge($this->creds(), [
            'productID' => $productId,
            'domain' => $domain,
            'years' => $years,
        ]);

        if (!empty($extra)) {
            $params = array_merge($params, $extra);
        }

        $res = $this->soap->__soapCall('purchaseSSL', [$params]);
        return (array) $res;
    }

    /* -----------------------------------------------------------------
     |  Account Balance
     |------------------------------------------------------------------*/

    /**
     * Get account balance.
     *
     * @return array Balance information
     */
    public function balanceQuery(): array
    {
        $params = $this->creds();
        $res = $this->soap->__soapCall('balanceQuery', [$params]);
        return (array) $res;
    }
}