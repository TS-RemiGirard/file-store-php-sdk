<?php

namespace Toosmart\FileStoreSdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;

class Client
{
    private string $baseUrl;
    private string $apiKey;
    private GuzzleClient $client;
    private CookieJar $cookieJar;
    private ?string $jwt = null;
    private ?string $bucket = null;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->cookieJar = new CookieJar();
        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'cookies' => $this->cookieJar,
            'http_errors' => false,
            'timeout' => 60,
        ]);
    }

    public function setBucket(string $bucket): void
    {
        $this->bucket = $bucket;
    }

    public function login(): string
    {
        // get CSRF token
        try {
            $csrfResponse = $this->client->get('/api/auth/csrf');
        } catch (RequestException $e) {
            throw new \RuntimeException('Failed to fetch CSRF token: ' . $e->getMessage());
        }

        $csrfData = json_decode((string)$csrfResponse->getBody(), true);
        if (!isset($csrfData['csrfToken'])) {
            throw new \RuntimeException('CSRF token not found in response.');
        }
        $csrfToken = $csrfData['csrfToken'];

        // login with token and CSRF
        try {
            $loginResponse = $this->client->post('/api/auth/callback/credentials', [
                'form_params' => [
                    'csrfToken' => $csrfToken,
                    'token' => $this->apiKey,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (RequestException $e) {
            throw new \RuntimeException('Login request failed: ' . $e->getMessage());
        }

        // extract JWT from cookie
        $cookies = $this->cookieJar->toArray();
        foreach ($cookies as $cookie) {
            if ($cookie['Name'] === 'jwt_token') {
                $this->jwt = $cookie['Value'];
                return $this->jwt;
            }
        }

        throw new \RuntimeException('JWT cookie not found after login.');
    }

    private function getJwt(): ?string
    {
        return $this->jwt;
    }

    public function getFile(string $filePath)
    {
        $this->jwtAndTokenSetOrThrow();
        $url = "{$this->baseUrl}/api/v2/file/{$this->bucket}/{$filePath}";
        return $this->requestWithAutoRelogin('GET', $url, [
            'headers' => [
                'Accept' => '*/*',
            ],
            'stream' => false,
        ], true, false);
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @param bool $retry
     * @param bool $decodeJson
     * @return mixed
     * @throws \RuntimeException
     */
    private function requestWithAutoRelogin(
        string $method,
        string $uri,
        array $options = [],
        bool $retry = true,
        bool $decodeJson = true,
    ) {
        try {
            $response = $this->client->request($method, $uri, $options);
        } catch (RequestException $e) {
            throw new \RuntimeException('Request failed: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status === 401 && $retry) {
            $this->login();
            return $this->requestWithAutoRelogin($method, $uri, $options, false, $decodeJson);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Request failed with status ' . $status . ': ' . (string)$response->getBody());
        }

        $body = (string)$response->getBody();
        return $decodeJson ? json_decode($body, true) : $body;
    }

    private function jwtAndTokenSetOrThrow(): void
    {
        if (!$this->jwt) {
            throw new \RuntimeException('Not authenticated. Please login first.');
        }
        if (!$this->bucket) {
            throw new \RuntimeException('No bucket set. Please select bucket first.');
        }
    }
}