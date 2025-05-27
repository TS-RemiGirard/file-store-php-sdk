<?php

namespace TooSmart\Wireframe\TooSmart\FileStoreSdk;

use TooSmart\Wireframe\GuzzleHttp\Client as GuzzleClient;
use TooSmart\Wireframe\GuzzleHttp\Cookie\CookieJar;
use TooSmart\Wireframe\GuzzleHttp\Exception\RequestException;

/**
 * FileStoreSdk Client for interacting with the TooSmart Object Storage API.
 */
class Client
{
    private string $baseUrl;
    private string $apiKey;
    private GuzzleClient $client;
    private CookieJar $cookieJar;
    private ?string $jwt = null;
    private ?string $bucket = null;

    /**
     * Client constructor.
     *
     * @param string $baseUrl
     * @param string $apiKey
     */
    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->cookieJar = new CookieJar();
        $this->client = new GuzzleClient([
            'base_uri'    => $this->baseUrl,
            'cookies'     => $this->cookieJar,
            'http_errors' => false,
            'timeout'     => 60,
        ]);
    }

    /**
     * Set the storage bucket.
     *
     * @param string $bucket
     */
    public function setBucket(string $bucket): void
    {
        $this->bucket = $bucket;
    }

    /**
     * Authenticate and obtain a JWT token.
     *
     * @return string
     * @throws \RuntimeException
     */
    public function login(): string
    {
        // Get CSRF token
        try {
            $csrfResponse = $this->client->get('/api/auth/csrf');
        } catch (RequestException $e) {
            throw new \RuntimeException('Failed to fetch CSRF token: ' . $e->getMessage());
        }

        $csrfData = json_decode((string) $csrfResponse->getBody(), true);
        if (!isset($csrfData['csrfToken'])) {
            throw new \RuntimeException('CSRF token not found in response.');
        }
        $csrfToken = $csrfData['csrfToken'];

        // Login with token and CSRF
        try {
            $loginResponse = $this->client->post('/api/auth/callback/credentials', [
                'form_params' => [
                    'csrfToken' => $csrfToken,
                    'token'     => $this->apiKey,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (RequestException $e) {
            throw new \RuntimeException('Login request failed: ' . $e->getMessage());
        }

        // Extract JWT from cookie
        foreach ($this->cookieJar->toArray() as $cookie) {
            if ($cookie['Name'] === 'jwt_token') {
                $this->jwt = $cookie['Value'];
                return $this->jwt;
            }
        }

        throw new \RuntimeException('JWT cookie not found after login.');
    }

    /**
     * Get the current JWT.
     *
     * @return string|null
     */
    private function getJwt(): ?string
    {
        return $this->jwt;
    }

    /**
     * Download a file from the storage bucket.
     *
     * @param string $filePath
     * @return mixed
     * @throws \RuntimeException
     */
    public function getFile(string $filePath)
    {
        $this->jwtAndTokenSetOrThrow();
        $url = "{$this->baseUrl}/api/v2/file/{$this->bucket}/{$filePath}";
        echo $url;
        return $this->requestWithAutoRelogin('GET', $url, [
            'headers' => ['Accept' => '*/*'],
            'stream'  => false,
        ], true, false);
    }

    /**
     * Make an authenticated request with automatic re-login on 401.
     *
     * @param string $method
     * @param string $uri
     * @param array  $options
     * @param bool   $retry
     * @param bool   $decodeJson
     * @return mixed
     * @throws \RuntimeException
     */
    private function requestWithAutoRelogin(
        string $method,
        string $uri,
        array $options = [],
        bool $retry = true,
        bool $decodeJson = true
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
            throw new \RuntimeException('Request failed with status ' . $status . ': ' . (string) $response->getBody());
        }

        $body = (string) $response->getBody();

        return [
            'body' => $decodeJson ? json_decode($body, true) : $body,
            'headers' => $response->getHeaders(),
        ];
    }

    /**
     * Ensure authentication and bucket are set, or throw.
     *
     * @throws \RuntimeException
     */
    private function jwtAndTokenSetOrThrow(): void
    {
        if (!$this->jwt) {
            throw new \RuntimeException('Not authenticated. Please login first.');
        }
        if (!$this->bucket) {
            throw new \RuntimeException('No bucket set. Please select bucket first.');
        }
    }

    /**
     * Upload content (file, path, or HTML) to the storage bucket.
     *
     * @param string $targetPath The path (including folder and filename) in the bucket.
     * @param array $contentList Array of ['type' => 'file'|'path'|'html', 'value' => mixed]
     * @param string|null $mimeType Optional MIME type (e.g., 'application/pdf')
     * @param bool $getUrl If true, response will include a link to the uploaded file.
     * @param bool $decodeJson Whether to decode the JSON response.
     * @return mixed
     * @throws \RuntimeException
     */
    public function uploadContentList(
        string $targetPath,
        array $contentList,
        ?string $mimeType = null,
        bool $getUrl = false,
        bool $decodeJson = true
    ) {
        $this->jwtAndTokenSetOrThrow();

        $url = "{$this->baseUrl}/api/v2/file/{$this->bucket}/{$targetPath}";

        $multipart = [];

        foreach ($contentList as $item) {
            switch ($item['type']) {
                case 'file':
                    // 'content_file' for file upload
                    $multipart[] = [
                        'name'     => 'content_file',
                        'contents' => fopen($item['value'], 'r'),
                        'filename' => basename($item['value']),
                    ];
                    break;
                case 'path':
                    // 'content_path' for file path string
                    $multipart[] = [
                        'name'     => 'content_path',
                        'contents' => $item['value'],
                    ];
                    break;
                case 'html':
                    // 'content_html' for HTML content
                    $multipart[] = [
                        'name'     => 'content_html',
                        'contents' => $item['value'],
                    ];
                    break;
            }
        }

        // Optional fields
        if ($mimeType) {
            $multipart[] = [
                'name'     => 'type',
                'contents' => $mimeType,
            ];
        }
        if ($getUrl) {
            $multipart[] = [
                'name'     => 'get_url',
                'contents' => 'true',
            ];
        }

        $headers = [
            'Accept' => 'application/json',
            // Don't set Content-Type here; Guzzle will set it with the multipart boundary.
        ];

        $options = [
            'headers'   => $headers,
            'multipart' => $multipart,
        ];

        // Use PUT as specified by the API doc
        return $this->requestWithAutoRelogin('PUT', $url, $options, true, $decodeJson);
    }

}
