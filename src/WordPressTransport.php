<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Contracts\HttpTransport;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardPhp\Http\HttpResponse;

/** Keep HideGuard requests within WordPress's HTTP and proxy configuration. */
final class WordPressTransport implements HttpTransport
{
    private const MAX_BYTES = 65536;
    private ?array $metadata = null;

    /** Safe HTTP metadata only; never retain headers, request bodies or response bodies. */
    public function debugMetadata(): ?array
    {
        return $this->metadata;
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $started = hrtime(true);
        $this->metadata = ['http_status' => null, 'duration_ms' => 0, 'transport_error' => null];
        $file = tmpfile();
        $configure = null;
        try {
            if ($file === false) {
                throw new ApiException('HideGuard response storage is unavailable.');
            }
            $filename = stream_get_meta_data($file)['uri'];
            // Configure only this file-backed request, including WordPress's Fsockopen fallback.
            $configure = function (
                $requestUrl,
                $requestHeaders,
                $requestBody,
                $method,
                array $options
            ) use ($filename): void {
                if (($options['filename'] ?? null) !== $filename) {
                    return;
                }
                $options['hooks']->register('curl.before_send', static function ($handle): void {
                    if (!curl_setopt($handle, CURLOPT_HTTP_CONTENT_DECODING, false)) {
                        throw new ApiException('HideGuard HTTP decoding could not be configured.');
                    }
                });
                $options['hooks']->register('request.progress', function (string $chunk, int $received): void {
                    if (strlen($chunk) > self::MAX_BYTES - $received) {
                        $this->metadata['transport_error'] = 'response_too_large';
                        throw new ApiException('HideGuard verdict response is too large.');
                    }
                });
            };
            add_action('requests-requests.before_request', $configure, PHP_INT_MAX, 5);
            $headers['Accept-Encoding'] = 'gzip, deflate';
            $response = wp_safe_remote_post($url, [
                'headers' => $headers, 'body' => $body, 'data_format' => 'body',
                'timeout' => 10, 'redirection' => 0, 'sslverify' => true,
                'stream' => true, 'filename' => $filename,
                'limit_response_size' => self::MAX_BYTES + 1,
            ]);
            if (is_wp_error($response)) {
                $this->metadata['transport_error'] = 'transport_error';
                // HTTP errors can contain proxy/transport details; do not expose them to the browser.
                throw new ApiException('HideGuard verdict service is unavailable.');
            }
            $this->metadata['http_status'] = (int)wp_remote_retrieve_response_code($response);
            // HTTP API preemptors may return an in-memory body instead of writing the stream.
            $content = wp_remote_retrieve_body($response);
            if ($content === '') {
                rewind($file);
                $content = stream_get_contents($file, self::MAX_BYTES + 1);
            }
            if ($content === false) {
                throw new ApiException('HideGuard verdict response could not be read.');
            }
            $encoding = wp_remote_retrieve_header($response, 'content-encoding');
            $encoding = is_string($encoding) ? strtolower(trim($encoding)) : 'unsupported';
            $content = $this->decode($content, $encoding);
            return new HttpResponse($this->metadata['http_status'], $content);
        } finally {
            if ($configure !== null) {
                remove_action('requests-requests.before_request', $configure, PHP_INT_MAX);
            }
            if (is_resource($file)) {
                fclose($file);
            }
            $this->metadata['duration_ms'] = round((hrtime(true) - $started) / 1000000, 2);
        }
    }

    private function decode(string $content, string $encoding): string
    {
        $this->checkSize($content);
        if ($encoding === '' || $encoding === 'identity') {
            return $content;
        }
        $decoded = match ($encoding) {
            'gzip', 'x-gzip' => function_exists('gzdecode') ? @gzdecode($content, self::MAX_BYTES + 1) : false,
            'deflate' => function_exists('gzuncompress') && function_exists('gzinflate')
                ? $this->inflate($content)
                : false,
            default => false,
        };
        if (!is_string($decoded)) {
            $this->metadata['transport_error'] = 'invalid_or_oversized_compression';
            throw new ApiException('HideGuard response encoding is invalid, unsupported or exceeds the size limit.');
        }
        $this->checkSize($decoded);
        return $decoded;
    }

    private function inflate(string $content): string|false
    {
        $decoded = @gzuncompress($content, self::MAX_BYTES + 1);
        return $decoded !== false ? $decoded : @gzinflate($content, self::MAX_BYTES + 1);
    }

    private function checkSize(string $content): void
    {
        if (strlen($content) > self::MAX_BYTES) {
            $this->metadata['transport_error'] = 'response_too_large';
            throw new ApiException('HideGuard verdict response is too large.');
        }
    }
}
