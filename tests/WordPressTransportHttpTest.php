<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardWordPress\WordPressTransport;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Transport\Curl;
use WpOrg\Requests\Transport\Fsockopen;

final class WordPressTransportHttpTest extends AdapterTestCase
{
    /** @dataProvider responses */
    public function testNativeStreamsBoundEncodedAndDecodedBodiesAndCleanUp(
        string $driver,
        string $encoding,
        int $size,
        bool $accepted
    ): void {
        $process = proc_open([
            PHP_BINARY, '-S', '127.0.0.1:18890', __DIR__ . '/fixtures/verdict-transport.php',
        ], [0 => ['pipe', 'r'], 1 => ['file', '/tmp/hg-transport-http.log', 'a'],
            2 => ['file', '/tmp/hg-transport-http.log', 'a']], $pipes);
        fclose($pipes[0]);
        $filename = null;
        $callbacks = $this->requestCallbacks();
        $mock = static function ($preempt, array $args, string $url) use (
            $driver, $encoding, $size, &$filename
        ): array {
            $filename = $args['filename'];
            $response = Requests::post(
                'http://127.0.0.1:18890/?' . http_build_query(['encoding' => $encoding, 'size' => $size]),
                $args['headers'],
                $args['body'],
                [
                    'transport' => $driver, 'filename' => $filename,
                    'max_bytes' => $args['limit_response_size'], 'follow_redirects' => false,
                    'hooks' => new WP_HTTP_Requests_Hooks($url, $args), 'timeout' => 2,
                ]
            );
            // A valid gzip stream must still be compressed before the adapter's bounded decode.
            if ($encoding === 'gzip' && $size <= 65536) {
                self::assertSame("\x1f\x8b", file_get_contents($filename, false, null, 0, 2));
            }
            clearstatcache(true, $filename);
            self::assertLessThanOrEqual(65537, filesize($filename));
            return (new WP_HTTP_Requests_Response($response, $filename))->to_array();
        };
        try {
            for ($i = 0; $i < 100; $i++) {
                $socket = @stream_socket_client('tcp://127.0.0.1:18890', $error, $message, 0.05);
                if ($socket !== false) {
                    fclose($socket);
                    break;
                }
                usleep(20000);
            }
            add_filter('pre_http_request', $mock, 1, 3);
            $transport = new WordPressTransport();
            try {
                $response = $transport->post('https://hideguard.io/api/v1/verdict', [], '{}');
                self::assertTrue($accepted, 'Invalid/oversized responses must be rejected.');
                self::assertSame(str_repeat('x', $size), $response->body);
            } catch (ApiException $error) {
                self::assertFalse($accepted, $error->getMessage());
                self::assertNotNull($transport->debugMetadata()['transport_error']);
            }
            self::assertNotNull($filename);
            self::assertFileDoesNotExist($filename);
            self::assertSame($callbacks, $this->requestCallbacks());
        } finally {
            remove_filter('pre_http_request', $mock, 1);
            proc_terminate($process);
            proc_close($process);
        }
    }

    public static function responses(): array
    {
        $cases = [];
        foreach ([Curl::class, Fsockopen::class] as $driver) {
            foreach (['identity', 'gzip', 'deflate', 'raw-deflate'] as $encoding) {
                foreach ([0, 32, 65536, 65537, 1048576] as $size) {
                    $cases[$driver . ':' . $encoding . ':' . $size] = [$driver, $encoding, $size, $size <= 65536];
                }
            }
            $cases[$driver . ':unsupported'] = [$driver, 'br', 32, false];
        }
        return $cases;
    }

    private function requestCallbacks(): int
    {
        return count($GLOBALS['wp_filter']['requests-requests.before_request']->callbacks[PHP_INT_MAX] ?? []);
    }
}
