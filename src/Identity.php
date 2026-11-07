<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\AccessContext;

/** Signed HttpOnly browser identity survives WordPress login/logout cookie changes. */
final class Identity
{
    private ?string $hash = null;

    public function context(): AccessContext
    {
        return new AccessContext((string)get_current_blog_id(), $this->hash(), get_current_user_id()
            ? (string)get_current_user_id() : null);
    }

    public function nonceAction(): string
    {
        return 'hachitech-hideguard:' . $this->hash();
    }

    private function hash(): string
    {
        if ($this->hash !== null) {
            return $this->hash;
        }
        $name = 'hachitech_browser_' . get_current_blog_id();
        $cookie = $_COOKIE[$name] ?? '';
        $parts = is_string($cookie) ? explode('.', $cookie) : [];
        $valid = count($parts) === 2 && preg_match('/^[a-f0-9]{64}$/D', $parts[0])
            && hash_equals(hash_hmac('sha256', $parts[0], wp_salt('auth')), $parts[1]);
        if (!$valid) {
            if (headers_sent()) {
                throw new \RuntimeException('Browser identity requires headers before output.');
            }
            $secret = bin2hex(random_bytes(32));
            $cookie = $secret . '.' . hash_hmac('sha256', $secret, wp_salt('auth'));
            if (
                !setcookie($name, $cookie, [
                'expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/',
                'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
                ])
            ) {
                throw new \RuntimeException('Unable to initialize browser identity.');
            }
            $_COOKIE[$name] = $cookie;
        }
        return $this->hash = hash('sha256', $cookie);
    }
}
