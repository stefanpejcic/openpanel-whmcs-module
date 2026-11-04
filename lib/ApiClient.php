<?php

namespace WHMCS\Module\Server\OpenPanel;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Client for the OpenAdmin REST API.
 *
 * OpenAdmin rate-limits logins per IP (a burst of 5, then `login_ratelimit`
 * per minute, shared with its own login form) and issues JWTs that live for
 * 15 minutes. Logging in for every call, as v1 did, trips that limit during
 * cron runs, so a token is reused for the rest of the PHP request and cached
 * (encrypted) in WHMCS transient data for 10 minutes.
 *
 * Every HTTP call goes to the WHMCS module log (when module logging is on)
 * with passwords and tokens masked.
 *
 * @see https://openpanel.com/docs/articles/dev-experience/openadmin-api/
 */
class ApiClient
{
    private const MODULE = 'openpanel';
    private const DEFAULT_PORT = 2087;
    private const CONNECT_TIMEOUT = 10;
    private const LOGIN_TIMEOUT = 30;
    private const TOKEN_CACHE_SECONDS = 600;

    /** @var array<string, string> tokens obtained earlier in this PHP request */
    private static array $tokens = [];

    private string $baseUrl;
    private string $username;
    private string $password;

    public function __construct(array $params)
    {
        $this->baseUrl = self::baseUrl($params);
        $this->username = (string) ($params['serverusername'] ?? '');
        $this->password = (string) ($params['serverpassword'] ?? '');

        if ($this->username === '' || $this->password === '') {
            throw new ApiException('The OpenAdmin username and password are not set for this server in WHMCS.');
        }
    }

    /**
     * HTTPS on a hostname. On a bare IP address, HTTPS when the server's
     * "Use SSL Mode for Connections" box is ticked in WHMCS, plain HTTP
     * otherwise: OpenAdmin serves HTTPS on an IP once Caddy holds a
     * certificate for it (Let's Encrypt issues IP certificates), and plain
     * HTTP when it has none.
     */
    public static function baseUrl(array $params): string
    {
        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            $host = trim((string) ($params['serverip'] ?? ''));
        }
        // tolerate "https://panel.example.com/" pasted into the hostname field
        $host = rtrim((string) preg_replace('#^[a-z]+://#i', '', $host), '/');
        if ($host === '') {
            throw new ApiException('This service is not assigned to a server with a hostname or IP address.');
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        $port = (int) ($params['serverport'] ?? 0) ?: self::DEFAULT_PORT;
        $https = !$isIp || !empty($params['serversecure']);

        return ($https ? 'https://' : 'http://') . $host . ':' . $port;
    }

    /**
     * Calls an endpoint that needs a JWT and returns the decoded JSON body.
     *
     * @throws ApiException on transport errors and HTTP 4xx/5xx answers
     */
    public function call(string $method, string $uri, ?array $data = null, int $timeout = 180): array
    {
        [$token, $reused] = $this->token();
        [$status, $body] = $this->send($method, $uri, $data, $token, $timeout);

        if ($status === 401 && $reused) {
            // the cached token was rejected (OpenAdmin restarted with a new key, clock skew, ...)
            $this->forgetToken();
            $token = $this->authenticate();
            [$status, $body] = $this->send($method, $uri, $data, $token, $timeout);
        }

        return $this->decode($status, $body);
    }

    /**
     * Logs in with the server credentials, bypassing the token caches.
     */
    public function authenticate(): string
    {
        [$status, $body] = $this->send('POST', '/api/', $this->credentials(), null, self::LOGIN_TIMEOUT);
        try {
            $data = $this->decode($status, $body);
        } catch (ApiException $e) {
            throw new ApiException('OpenAdmin login failed: ' . $e->getMessage(), $e->getCode());
        }
        if (!is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new ApiException('OpenAdmin login failed: the response did not include an access token.', $status);
        }

        $key = $this->cacheKey();
        self::$tokens[$key] = $data['access_token'];
        $this->storeToken($key, $data['access_token']);

        return $data['access_token'];
    }

    /**
     * One-time OpenAdmin session link for the server's admin user. The token
     * in it is single-use and expires after two minutes.
     */
    public function adminLoginUrl(): string
    {
        [$status, $body] = $this->send('POST', '/api/login', $this->credentials(), null, self::LOGIN_TIMEOUT);
        $data = $this->decode($status, $body);
        if (!is_string($data['login_path'] ?? null) || $data['login_path'] === '') {
            throw new ApiException('OpenAdmin did not return a login link.');
        }

        return $this->baseUrl . $data['login_path'];
    }

    /**
     * @return array{0: string, 1: bool} the token, and whether it came from a cache
     */
    private function token(): array
    {
        $key = $this->cacheKey();
        if (isset(self::$tokens[$key])) {
            return [self::$tokens[$key], true];
        }

        $stored = $this->loadStoredToken($key);
        if ($stored !== null) {
            self::$tokens[$key] = $stored;
            return [$stored, true];
        }

        return [$this->authenticate(), false];
    }

    private function cacheKey(): string
    {
        // the password is part of the key so changing it in WHMCS drops the old token
        return 'openpanel_jwt_' . hash('sha256', $this->baseUrl . "\n" . $this->username . "\n" . $this->password);
    }

    // Caching across requests is only an optimisation: if TransientData or
    // encrypt()/decrypt() are unavailable or fail, the module just logs in.

    private function loadStoredToken(string $key): ?string
    {
        try {
            $stored = \WHMCS\TransientData::getInstance()->retrieve($key);
            $token = is_string($stored) && $stored !== '' ? (string) decrypt($stored) : '';
        } catch (\Throwable $e) {
            return null;
        }

        return $token !== '' ? $token : null;
    }

    private function storeToken(string $key, string $token): void
    {
        try {
            \WHMCS\TransientData::getInstance()->store($key, encrypt($token), self::TOKEN_CACHE_SECONDS);
        } catch (\Throwable $e) {
        }
    }

    private function forgetToken(): void
    {
        $key = $this->cacheKey();
        unset(self::$tokens[$key]);
        try {
            \WHMCS\TransientData::getInstance()->delete($key);
        } catch (\Throwable $e) {
        }
    }

    private function credentials(): array
    {
        return ['username' => $this->username, 'password' => $this->password];
    }

    /**
     * @return array{0: int, 1: string} HTTP status and raw response body
     */
    private function send(string $method, string $uri, ?array $data, ?string $token, int $timeout): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ($method !== 'GET') {
            // OpenAdmin's write endpoints (DELETE and autologin included) insist on a JSON body
            $options[CURLOPT_POSTFIELDS] = json_encode($data ?? new \stdClass(), JSON_THROW_ON_ERROR);
        }

        $curl = curl_init($this->baseUrl . $uri);
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? curl_error($curl) : '';

        // the request is stringified here, not JSON-encoded, so the raw
        // password text is still there for the replaceVars masking to find
        logModuleCall(
            self::MODULE,
            $method . ' ' . $uri,
            $data === null ? '' : print_r($data, true),
            $body === false ? $error : $body,
            $status > 0 ? 'HTTP ' . $status : '',
            $this->secrets($data, $token, $body)
        );

        if ($body === false) {
            throw new ApiException('Could not connect to OpenAdmin at ' . $this->baseUrl . ': ' . $error);
        }

        return [$status, (string) $body];
    }

    private function decode(int $status, string $body): array
    {
        $data = json_decode($body, true);

        if ($status >= 400) {
            $message = '';
            if (is_array($data)) {
                $message = is_string($data['error'] ?? null) ? $data['error'] : (is_string($data['message'] ?? null) ? $data['message'] : '');
            }
            throw new ApiException(trim($message) !== '' ? trim($message) : 'OpenAdmin answered with HTTP ' . $status . '.', $status);
        }
        if (!is_array($data)) {
            throw new ApiException('OpenAdmin returned a response that is not JSON (HTTP ' . $status . ').', $status);
        }

        return $data;
    }

    /**
     * Values logModuleCall() must mask: passwords, the JWT, and one-time
     * login tokens in SSO responses.
     *
     * @param string|false $body
     */
    private function secrets(?array $data, ?string $token, $body): array
    {
        $secrets = [$this->password, $token, $data['password'] ?? null];

        $response = is_string($body) ? json_decode($body, true) : null;
        if (is_array($response)) {
            $secrets[] = $response['access_token'] ?? null;
            $secrets[] = $response['token'] ?? null;
            if (is_string($response['link'] ?? null)) {
                parse_str((string) parse_url($response['link'], PHP_URL_QUERY), $query);
                $secrets[] = $query['admin_token'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($secrets, static fn ($s) => is_string($s) && $s !== '')));
    }
}
