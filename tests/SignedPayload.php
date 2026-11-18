<?php

declare(strict_types=1);

/** Test-only signer; no signing key is saved to site settings or sent in frontend HTML. */
final class SignedPayload
{
    private static ?string $secret = null;

    public static function secret(): string
    {
        return self::$secret ??= sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    }

    public static function pem(): string
    {
        $key = sodium_crypto_sign_publickey_from_secretkey(self::secret());
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin('302a300506032b6570032100') . $key), 64, "\n")
            . "-----END PUBLIC KEY-----";
    }

    public static function payload(
        string $verdict = 'allow',
        string $visitor = 'fixture-visitor',
        string $environment = 'production',
        ?int $issuedAt = null
    ): array {
        $issuedAt ??= time();
        $payload = [
            'siteId' => 'fixture-site', 'environment' => $environment,
            'requestId' => bin2hex(random_bytes(8)), 'visitorId' => $visitor,
            'verdict' => $verdict, 'score' => 0, 'reasons' => [],
        ];
        $message = implode("\n", [
            'hideguard-collect-ed25519-v2', 'alg=ed25519', 'kid=fixture-key', 't=' . $issuedAt,
            'site=' . $payload['siteId'], 'environment=' . $environment,
            'request=' . $payload['requestId'], 'visitor=' . $visitor,
            'verdict=' . $verdict, 'score=0', 'reasons=[]',
        ]);
        $signature = sodium_crypto_sign_detached($message, self::secret());
        $payload['signature'] = 'v=2,alg=ed25519,kid=fixture-key,t=' . $issuedAt . ',sig='
            . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        return $payload;
    }
}
