<?php

namespace App\Services\Vocus;

use SoapClient;
use SoapFault;

/**
 * SoapClient that can pin the WSM hostname to a fixed IP address.
 *
 * The Vocus sandbox shares the production hostname (wsm.webservice.m2.com.au)
 * but is only reachable at an unadvertised IP (Sandbox guide 2.1), which
 * normally means editing the OS hosts file. With a pinned IP, requests are
 * sent with cURL's CURLOPT_RESOLVE so the TLS name and Host header stay the
 * hostname while the connection goes to the pinned address. TLS verification
 * stays on (use a CA bundle for the sandbox's self-signed certificate).
 */
class VocusSoapClient extends SoapClient
{
    private string $sessionId = '';
    private ?string $pinnedIp = null;
    private ?string $caBundle = null;
    private int $connectTimeout = 10;
    private int $requestTimeout = 30;

    public function configureTransport(string $sessionId, ?string $pinnedIp, ?string $caBundle, int $connectTimeout, int $requestTimeout): static
    {
        $this->sessionId = $sessionId;
        $this->pinnedIp = $pinnedIp;
        $this->caBundle = $caBundle;
        $this->connectTimeout = $connectTimeout;
        $this->requestTimeout = $requestTimeout;

        return $this;
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false): ?string
    {
        if ($this->pinnedIp === null) {
            return parent::__doRequest($request, $location, $action, $version, $oneWay);
        }

        $parts = parse_url($location);
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);

        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $request,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$this->pinnedIp}"],
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $action . '"',
                'Cookie: JSESSIONID=' . $this->sessionId,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => max($this->connectTimeout, $this->requestTimeout),
        ];

        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        $ch = curl_init($location);
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new SoapFault('HTTP', 'Could not connect to Vocus WSM at the pinned address: ' . $error);
        }

        // SOAP faults arrive with HTTP 500 and a fault envelope; SoapClient parses the body.
        return $oneWay ? null : (string) $body;
    }
}
