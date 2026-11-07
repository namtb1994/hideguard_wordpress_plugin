<?php

declare(strict_types=1);

abstract class HttpTestCase extends AdapterTestCase
{
    protected mixed $process;
    protected array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->process = proc_open([
            PHP_BINARY, '-S', '127.0.0.1:18889', '-t', (string)getenv('HG_WP_ROOT'), __DIR__ . '/router.php',
        ], [0 => ['pipe', 'r'], 1 => ['file', '/tmp/hachitech-wp-server.log', 'a'],
            2 => ['file', '/tmp/hachitech-wp-server.log', 'a']], $pipes);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:18889', $error, $message, 0.05);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(20000);
        }
        self::fail('HTTP fixture did not start.');
    }

    protected function tearDown(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    protected function pageConfig(): array
    {
        [$status, $page, $headers] = $this->request('/protected/');
        self::assertSame(200, $status, $page);
        self::assertStringContainsString('Original protected fixture content', $page);
        self::assertMatchesRegularExpression('/<body[^>]*\binert\b/', $page);
        self::assertTrue($this->hasHeader($headers, 'Cache-Control: private, no-store'));
        return $this->extractConfig($page);
    }

    protected function orderCount(): int
    {
        // Orders and HPOS configuration can change in the separate HTTP process.
        wp_cache_flush();
        return count(wc_get_orders(['limit' => -1, 'return' => 'ids']));
    }

    protected function extractConfig(string $page): array
    {
        self::assertSame(1, preg_match(
            '#<script\b[^>]*id="wp-script-module-data-hachitech-hideguard"[^>]*>(.*?)</script>#s',
            $page, $match
        ));
        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    protected function tokens(array $config): array
    {
        return ['_hg_nonce' => $config['nonce'], '_wpnonce' => $config['restNonce'],
            'verificationId' => $config['verificationId']];
    }

    protected function verdict(array $config, array $payload): array
    {
        return $this->request('/wp-json/hachitech-hideguard/v1/verdict',
            $this->tokens($config) + ['response' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    protected function hasHeader(array $headers, string $prefix): bool
    {
        return count(array_filter($headers, static fn(string $header): bool => str_starts_with($header, $prefix))) > 0;
    }

    protected function request(string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $headers = array_merge(['Accept: ' . ($body === null ? 'text/html' : 'application/json')], $extraHeaders);
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($this->cookies), array_values($this->cookies)
            ));
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : http_build_query($body), 'ignore_errors' => true,
            'follow_location' => 0, 'timeout' => 20,
        ]]);
        $result = file_get_contents('http://127.0.0.1:18889' . $path, false, $context);
        self::assertNotFalse($result);
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) {
                $this->cookies[$match[1]] = $match[2];
            }
        }
        preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0], $status);
        return [(int)$status[1], $result, $http_response_header];
    }
}
