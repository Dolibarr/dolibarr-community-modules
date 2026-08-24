<?php

/**
 * HTTP Mock system for testing StancerApi
 *
 * Usage:
 *   HttpMock::reset();
 *   HttpMock::addResponse('https://api.stancer.com/v1/customers/', [
 *       'http_code' => 200,
 *       'content' => json_encode(['id' => 'cust_xxx', 'email' => 'test@example.com']),
 *   ]);
 *
 *   // Now any call to getURLContent() matching this URL will return the mocked response
 */
class HttpMock
{
    /**
     * @var array Queued responses indexed by URL pattern
     */
    private static $responses = [];

    /**
     * @var array History of all requests made
     */
    private static $requestHistory = [];

    /**
     * @var bool Whether mock is active
     */
    private static $active = false;

    /**
     * Reset all mocked responses and history
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$responses = [];
        self::$requestHistory = [];
        self::$active = true;
    }

    /**
     * Disable mock (pass through to real getURLContent if available)
     *
     * @return void
     */
    public static function disable(): void
    {
        self::$active = false;
    }

    /**
     * Check if mock is active
     *
     * @return bool
     */
    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Add a mocked response for a URL pattern
     *
     * @param string $urlPattern URL or pattern to match (supports * wildcard)
     * @param array  $response   Response array with keys: http_code, content, curl_error_no, curl_error_msg
     * @return void
     */
    public static function addResponse(string $urlPattern, array $response): void
    {
        $defaults = [
            'http_code' => 200,
            'content' => '',
            'curl_error_no' => 0,
            'curl_error_msg' => '',
        ];

        self::$responses[] = [
            'pattern' => $urlPattern,
            'response' => array_merge($defaults, $response),
        ];
    }

    /**
     * Add a successful JSON response
     *
     * @param string $urlPattern URL pattern
     * @param array  $data       Data to return as JSON
     * @param int    $httpCode   HTTP status code (default 200)
     * @return void
     */
    public static function addJsonResponse(string $urlPattern, array $data, int $httpCode = 200): void
    {
        self::addResponse($urlPattern, [
            'http_code' => $httpCode,
            'content' => json_encode($data),
        ]);
    }

    /**
     * Add an error response
     *
     * @param string $urlPattern URL pattern
     * @param int    $httpCode   HTTP error code
     * @param string $errorType  Error type (e.g., 'invalid_request_error')
     * @param string $message    Error message
     * @return void
     */
    public static function addErrorResponse(string $urlPattern, int $httpCode, string $errorType, string $message): void
    {
        self::addResponse($urlPattern, [
            'http_code' => $httpCode,
            'content' => json_encode([
                'error' => [
                    'type' => $errorType,
                    'message' => $message,
                ],
            ]),
        ]);
    }

    /**
     * Add a CURL error response
     *
     * @param string $urlPattern URL pattern
     * @param int    $errorNo    CURL error number
     * @param string $errorMsg   CURL error message
     * @return void
     */
    public static function addCurlError(string $urlPattern, int $errorNo, string $errorMsg): void
    {
        self::addResponse($urlPattern, [
            'http_code' => 0,
            'content' => '',
            'curl_error_no' => $errorNo,
            'curl_error_msg' => $errorMsg,
        ]);
    }

    /**
     * Get mocked response for a URL
     *
     * @param string $url    Request URL
     * @param string $method HTTP method
     * @param string $data   Request data
     * @return array|null Response or null if no match
     */
    public static function getResponse(string $url, string $method = 'GET', string $data = ''): ?array
    {
        // Record request in history
        self::$requestHistory[] = [
            'url' => $url,
            'method' => $method,
            'data' => $data,
            'timestamp' => time(),
        ];

        // Find matching response
        foreach (self::$responses as $key => $entry) {
            if (self::matchesPattern($url, $entry['pattern'])) {
                // Remove from queue (each response is used once)
                $response = $entry['response'];
                array_splice(self::$responses, $key, 1);
                return $response;
            }
        }

        // No match found - return a default 404
        return [
            'http_code' => 404,
            'content' => json_encode(['error' => ['message' => 'No mock response configured for: ' . $url]]),
            'curl_error_no' => 0,
            'curl_error_msg' => '',
        ];
    }

    /**
     * Check if URL matches pattern
     *
     * @param string $url     URL to check
     * @param string $pattern Pattern (supports * wildcard)
     * @return bool
     */
    private static function matchesPattern(string $url, string $pattern): bool
    {
        // Exact match
        if ($url === $pattern) {
            return true;
        }

        // Wildcard pattern
        if (strpos($pattern, '*') !== false) {
            $regex = '/^' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '$/';
            return (bool) preg_match($regex, $url);
        }

        // URL starts with pattern
        if (strpos($url, $pattern) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Get request history
     *
     * @return array
     */
    public static function getHistory(): array
    {
        return self::$requestHistory;
    }

    /**
     * Get last request
     *
     * @return array|null
     */
    public static function getLastRequest(): ?array
    {
        return !empty(self::$requestHistory) ? end(self::$requestHistory) : null;
    }

    /**
     * Assert that a request was made to a URL
     *
     * @param string $urlPattern URL pattern to check
     * @param string $method     Expected HTTP method (optional)
     * @return bool
     */
    public static function wasRequested(string $urlPattern, string $method = ''): bool
    {
        foreach (self::$requestHistory as $request) {
            if (self::matchesPattern($request['url'], $urlPattern)) {
                if (empty($method) || $request['method'] === $method) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get count of remaining mocked responses
     *
     * @return int
     */
    public static function remainingResponses(): int
    {
        return count(self::$responses);
    }
}
