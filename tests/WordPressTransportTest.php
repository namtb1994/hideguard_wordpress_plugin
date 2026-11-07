<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardWordPress\WordPressTransport;

final class WordPressTransportTest extends AdapterTestCase
{
    private ?Closure $httpMock = null;

    protected function tearDown(): void
    {
        if ($this->httpMock !== null) {
            remove_filter('pre_http_request', $this->httpMock, 1);
        }
        parent::tearDown();
    }

    public function testTransportKeepsTlsVerificationAndBoundsTimeoutRedirectsAndResponseSize(): void
    {
        $this->mock(function (array $args, string $url): array {
            self::assertSame('https://hideguard.io/api/v1/verdict', $url);
            self::assertSame('POST', $args['method']);
            self::assertTrue($args['sslverify']);
            self::assertTrue($args['reject_unsafe_urls']);
            self::assertSame(0, $args['redirection']);
            self::assertGreaterThan(0, $args['timeout']);
            self::assertLessThanOrEqual(10, $args['timeout']);
            self::assertSame(65537, $args['limit_response_size']);
            self::assertSame(['x-api-key' => 'private-fixture-key', 'Accept-Encoding' => 'gzip, deflate'], $args['headers']);
            self::assertTrue($args['stream']);
            self::assertFileExists($args['filename']);
            self::assertSame('{"visitorId":"fixture"}', $args['body']);
            return ['headers' => [], 'body' => '{"verdict":"allow"}', 'response' => ['code' => 200]];
        });
        $result = (new WordPressTransport())->post(
            'https://hideguard.io/api/v1/verdict', ['x-api-key' => 'private-fixture-key'], '{"visitorId":"fixture"}'
        );
        self::assertSame(200, $result->statusCode);
        self::assertSame('{"verdict":"allow"}', $result->body);
    }

    public function testTransportRejectsOversizedResponses(): void
    {
        $this->mock(static fn(): array => [
            'headers' => [], 'body' => str_repeat('x', 65537), 'response' => ['code' => 200],
        ]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HideGuard verdict response is too large.');
        (new WordPressTransport())->post('https://hideguard.io/api/v1/verdict', [], '{}');
    }

    public function testTransportAcceptsTheResponseSizeBoundary(): void
    {
        $this->mock(static fn(): array => [
            'headers' => [], 'body' => str_repeat('x', 65536), 'response' => ['code' => 200],
        ]);
        $response = (new WordPressTransport())->post('https://hideguard.io/api/v1/verdict', [], '{}');
        self::assertSame(65536, strlen($response->body));
    }

    public function testTransportDoesNotExposeNativeHttpErrorDetails(): void
    {
        $this->mock(static fn(): WP_Error => new WP_Error('http_request_failed', 'Private proxy credential detail'));
        try {
            (new WordPressTransport())->post('https://hideguard.io/api/v1/verdict', [], '{}');
            self::fail('A transport failure must stop the verdict check.');
        } catch (ApiException $error) {
            self::assertSame('HideGuard verdict service is unavailable.', $error->getMessage());
            self::assertStringNotContainsString('Private proxy', $error->getMessage());
        }
    }

    private function mock(callable $response): void
    {
        $this->httpMock = static fn($preempt, array $args, string $url) => $response($args, $url);
        add_filter('pre_http_request', $this->httpMock, 1, 3);
    }
}
