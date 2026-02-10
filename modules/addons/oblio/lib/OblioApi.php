<?php

namespace WHMCS\Module\Addon\Oblio;

/**
 * Oblio API Client
 *
 * Handles authentication and API communication with Oblio.eu
 * Documentation: https://www.oblio.eu/api
 */
class OblioApi
{
    const BASE_URL = 'https://www.oblio.eu';
    const TOKEN_ENDPOINT = '/api/authorize/token';
    const DOCS_ENDPOINT = '/api/docs/';
    const NOMENCLATURE_ENDPOINT = '/api/nomenclature/';

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var string|null */
    private $accessToken;

    /** @var int */
    private $tokenExpiresAt = 0;

    /**
     * @param string $clientId    Oblio API email/client ID
     * @param string $clientSecret Oblio API secret key
     */
    public function __construct($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Authenticate with the Oblio API using OAuth2 client credentials.
     *
     * @return string Access token
     * @throws \Exception
     */
    public function authenticate()
    {
        if ($this->accessToken && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $response = $this->makeRequest('POST', self::TOKEN_ENDPOINT, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ], false);

        if (empty($response['access_token'])) {
            throw new \Exception('Oblio API authentication failed: ' . json_encode($response));
        }

        $this->accessToken = $response['access_token'];
        // Subtract 60 seconds as a buffer to account for clock skew and prevent
        // authentication failures if the token expires during a request.
        $this->tokenExpiresAt = time() + (int)($response['expires_in'] ?? 3600) - 60;

        return $this->accessToken;
    }

    /**
     * Create a document (invoice or proforma) in Oblio.
     *
     * @param string $type Document type: 'invoice' or 'proforma'
     * @param array  $data Document data
     * @return array API response
     * @throws \Exception
     */
    public function createDocument($type, array $data)
    {
        $this->authenticate();
        return $this->makeRequest('POST', self::DOCS_ENDPOINT . $type, $data, true);
    }

    /**
     * Get a document from Oblio.
     *
     * @param string $type       Document type: 'invoice' or 'proforma'
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @return array API response
     * @throws \Exception
     */
    public function getDocument($type, $seriesName, $number)
    {
        $this->authenticate();
        $query = http_build_query([
            'seriesName' => $seriesName,
            'number'     => $number,
        ]);
        return $this->makeRequest('GET', self::DOCS_ENDPOINT . $type . '?' . $query, [], true);
    }

    /**
     * Delete a document from Oblio.
     *
     * @param string $type       Document type: 'invoice' or 'proforma'
     * @param string $seriesName Series name
     * @param string $number     Document number
     * @return array API response
     * @throws \Exception
     */
    public function deleteDocument($type, $seriesName, $number)
    {
        $this->authenticate();
        $query = http_build_query([
            'seriesName' => $seriesName,
            'number'     => $number,
        ]);
        return $this->makeRequest('DELETE', self::DOCS_ENDPOINT . $type . '?' . $query, [], true);
    }

    /**
     * Get companies from Oblio nomenclature.
     *
     * @return array
     * @throws \Exception
     */
    public function getCompanies()
    {
        $this->authenticate();
        return $this->makeRequest('GET', self::NOMENCLATURE_ENDPOINT . 'companies', [], true);
    }

    /**
     * Get series for a specific company and document type.
     *
     * @param string $cif  Company CIF
     * @param string $type Document type: 'invoice' or 'proforma'
     * @return array
     * @throws \Exception
     */
    public function getSeries($cif, $type)
    {
        $this->authenticate();
        $query = http_build_query([
            'cif'  => $cif,
            'type' => $type,
        ]);
        return $this->makeRequest('GET', self::NOMENCLATURE_ENDPOINT . 'series?' . $query, [], true);
    }

    /**
     * Get VAT rates for a specific company.
     *
     * @param string $cif Company CIF
     * @return array
     * @throws \Exception
     */
    public function getVatRates($cif)
    {
        $this->authenticate();
        $query = http_build_query(['cif' => $cif]);
        return $this->makeRequest('GET', self::NOMENCLATURE_ENDPOINT . 'vat_rates?' . $query, [], true);
    }

    /**
     * Make an HTTP request to the Oblio API.
     *
     * @param string $method   HTTP method
     * @param string $endpoint API endpoint path
     * @param array  $data     Request data
     * @param bool   $useAuth  Whether to include authorization header
     * @return array Decoded response
     * @throws \Exception
     */
    private function makeRequest($method, $endpoint, array $data, $useAuth)
    {
        $url = self::BASE_URL . $endpoint;

        $headers = [
            'Accept: application/json',
        ];

        if ($useAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $ch = curl_init();

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('Oblio API cURL error: ' . $curlError);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = isset($decoded['statusMessage'])
                ? $decoded['statusMessage']
                : (isset($decoded['error']) ? $decoded['error'] : $response);
            throw new \Exception('Oblio API error (HTTP ' . $httpCode . '): ' . $errorMsg);
        }

        return $decoded ?: [];
    }
}
