<?php

namespace Stancer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base class for HTTP functional tests
 *
 * Launches PHP built-in server with dolibarr-integration-sqlite bootstrap
 * and makes real HTTP requests to test headers, status codes, and responses.
 */
abstract class HttpTestCase extends TestCase
{
    /** @var int Server port */
    protected static int $serverPort = 8899;

    /** @var int|null Server process ID */
    protected static ?int $serverPid = null;

    /** @var string Server base URL */
    protected static string $baseUrl;

    /** @var HttpClientInterface HTTP client */
    protected HttpClientInterface $client;

    /**
     * Get the path to the router script.
     * Override in subclasses to use a different router.
     */
    protected static function getRouterPath(): string
    {
        return dirname(__DIR__, 3) . '/test/http/admin-router.php';
    }

    /**
     * Start the PHP built-in server before all tests in this class
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $projectRoot = dirname(__DIR__, 3);
        $routerPath = static::getRouterPath();
        $documentRoot = $projectRoot;

        // Find an available port
        self::$serverPort = self::findAvailablePort(8899);
        self::$baseUrl = 'http://127.0.0.1:' . self::$serverPort;

        // Start PHP built-in server
        $command = sprintf(
            'php -S 127.0.0.1:%d -t %s %s > /tmp/php_http_test_%d.log 2>&1 & echo $!',
            self::$serverPort,
            escapeshellarg($documentRoot),
            escapeshellarg($routerPath),
            self::$serverPort
        );

        $output = [];
        exec($command, $output);
        self::$serverPid = (int) ($output[0] ?? 0);

        if (self::$serverPid <= 0) {
            throw new \RuntimeException('Failed to start PHP built-in server');
        }

        // Wait for server to be ready
        $maxAttempts = 50;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $socket = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                break;
            }
            usleep(100000); // 100ms
            $attempt++;
        }

        if ($attempt >= $maxAttempts) {
            self::stopServer();
            throw new \RuntimeException(
                'PHP server did not start in time. Check /tmp/php_http_test_' . self::$serverPort . '.log'
            );
        }
    }

    /**
     * Stop the PHP built-in server after all tests
     */
    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        // Restore real main.inc.php if shim was installed
        $projectRoot = dirname(__DIR__, 3);
        $dolibarrPath = realpath($projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite/htdocs');
        if ($dolibarrPath) {
            $realMainBackup = $dolibarrPath . '/main.inc.php.real';
            $mainPath = $dolibarrPath . '/main.inc.php';
            if (file_exists($realMainBackup)) {
                copy($realMainBackup, $mainPath);
                unlink($realMainBackup);
            }
        }

        // Clean up shims created by admin-router
        $rootShim = $projectRoot . '/main.inc.php';
        if (is_file($rootShim)) {
            @unlink($rootShim);
        }
        $parentShim = dirname($projectRoot) . '/main.inc.php';
        if (is_file($parentShim)) {
            @unlink($parentShim);
        }

        parent::tearDownAfterClass();
    }

    /**
     * Stop the server process
     */
    protected static function stopServer(): void
    {
        if (self::$serverPid !== null && self::$serverPid > 0) {
            exec('kill ' . self::$serverPid . ' 2>/dev/null');
            exec('pkill -P ' . self::$serverPid . ' 2>/dev/null');
            self::$serverPid = null;
        }
    }

    /**
     * Find an available port starting from the given port
     */
    protected static function findAvailablePort(int $startPort): int
    {
        $port = $startPort;
        $maxPort = $startPort + 100;

        while ($port < $maxPort) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (!$socket) {
                return $port;
            }
            fclose($socket);
            $port++;
        }

        throw new \RuntimeException('Could not find available port in range ' . $startPort . '-' . $maxPort);
    }

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->client = HttpClient::create([
            'timeout' => 10,
            'max_redirects' => 0,
        ]);
    }

    /**
     * Make a GET request
     */
    protected function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, [], $headers);
    }

    /**
     * Make a POST request
     */
    protected function post(string $path, array $body = [], array $headers = []): array
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /**
     * Make an HTTP request and return response data
     *
     * @return array{statusCode: int, headers: array, body: string, json: ?array}
     */
    protected function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $url = self::$baseUrl . $path;

        $options = ['headers' => $headers];
        if (!empty($body) && $method !== 'GET') {
            $options['body'] = $body;
        }

        $response = $this->client->request($method, $url, $options);

        $statusCode = $response->getStatusCode();
        $responseHeaders = $response->getHeaders(false);
        $responseBody = $response->getContent(false);

        // Try to decode JSON
        $json = null;
        $contentType = $responseHeaders['content-type'][0] ?? '';
        if (strpos($contentType, 'json') !== false) {
            $json = json_decode($responseBody, true);
        }

        return [
            'statusCode' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'json' => $json,
        ];
    }

    /**
     * Assert that response has a specific status code
     */
    protected function assertStatusCode(int $expected, array $response): void
    {
        $this->assertEquals(
            $expected,
            $response['statusCode'],
            "Expected status code $expected, got {$response['statusCode']}. Body: " . substr($response['body'], 0, 500)
        );
    }

    /**
     * Assert that a page response contains no PHP errors (fatal, parse, etc.)
     */
    protected function assertNoPhpError(array $response, string $pageLabel): void
    {
        $body = $response['body'];
        $excerpt = substr($body, 0, 2000);

        $this->assertNotEquals(
            500,
            $response['statusCode'],
            "$pageLabel returned 500. Body: $excerpt"
        );

        $patterns = [
            'Fatal error:',
            'Uncaught Error:',
            'Call to undefined method',
            'Call to undefined function',
            'Class .* not found',
            'Parse error:',
            'syntax error, unexpected',
            'PHPUNIT_FATAL_ERROR:',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $body)) {
                $message = "$pageLabel contains PHP error matching '$pattern'. Body: $excerpt";

                $extra = $this->scanModuleForSymbol($body);
                if ($extra !== '') {
                    $message .= "\n\n" . $extra;
                }

                $this->fail($message);
            }
        }
    }

    /**
     * Extract the undefined symbol from an error body and search all module PHP files
     * for other occurrences.
     *
     * Handles:
     *   - Call to undefined method ClassName::methodName()
     *   - Call to undefined function functionName()
     *   - Class "ClassName" not found
     */
    private function scanModuleForSymbol(string $body): string
    {
        $symbol = '';
        $symbolType = '';

        if (preg_match('/Call to undefined method \S+::(\w+)\(\)/i', $body, $m)) {
            $symbol = $m[1];
            $symbolType = 'method';
        } elseif (preg_match('/Call to undefined function (\w+)\(\)/i', $body, $m)) {
            $symbol = $m[1];
            $symbolType = 'function';
        } elseif (preg_match('/Class ["\']?(\w+)["\']? not found/i', $body, $m)) {
            $symbol = $m[1];
            $symbolType = 'class';
        }

        if ($symbol === '') {
            return '';
        }

        $projectRoot = dirname(__DIR__, 3);
        $hits = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('#/(vendor|test|tmp|build|node_modules|docs)/#', $path)) {
                continue;
            }

            $content = file_get_contents($path);
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (stripos($line, $symbol) !== false) {
                    $relativePath = str_replace($projectRoot . '/', '', $path);
                    $hits[] = $relativePath . ':' . ($lineNum + 1) . ': ' . trim($line);
                }
            }
        }

        if (empty($hits)) {
            return '';
        }

        return sprintf(
            "--- Full module scan: %s \"%s\" found in %d location(s) ---\n%s",
            $symbolType,
            $symbol,
            count($hits),
            implode("\n", $hits)
        );
    }

    /**
     * Assert JSON key equals value
     */
    protected function assertJsonEquals(string $key, $expected, array $response): void
    {
        $this->assertJsonHasKey($key, $response);
        $this->assertEquals($expected, $response['json'][$key]);
    }

    /**
     * Assert JSON key exists
     */
    protected function assertJsonHasKey(string $key, array $response): void
    {
        $this->assertJsonResponse($response);
        $this->assertArrayHasKey($key, $response['json']);
    }

    /**
     * Assert JSON response
     */
    protected function assertJsonResponse(array $response): void
    {
        $this->assertHeaderContains('content-type', 'json', $response);
        $this->assertNotNull($response['json'], 'Response is not valid JSON');
    }

    /**
     * Assert that response body contains a string
     */
    protected function assertBodyContains(string $needle, array $response): void
    {
        $this->assertStringContainsString($needle, $response['body']);
    }

    /**
     * Assert that response has a specific header
     */
    protected function assertHeader(string $name, string $expectedValue, array $response): void
    {
        $name = strtolower($name);
        $this->assertArrayHasKey($name, $response['headers'], "Header '$name' not found");
        $this->assertEquals($expectedValue, $response['headers'][$name][0]);
    }

    /**
     * Assert that response header contains a value
     */
    protected function assertHeaderContains(string $name, string $needle, array $response): void
    {
        $name = strtolower($name);
        $this->assertArrayHasKey($name, $response['headers'], "Header '$name' not found");
        $this->assertStringContainsString($needle, $response['headers'][$name][0]);
    }
}
