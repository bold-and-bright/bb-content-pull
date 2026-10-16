<?php

namespace BB\ContentPull;

/**
 * Short-lived token a source hands out after a login with Kirby credentials,
 * so a pull checks the password once instead of on every file request.
 *
 *   u1.<base64url payload>.<base64url HMAC-SHA256>
 *   payload: {"u": user id, "e": expiry timestamp}
 *
 * The signing key is derived from the user's password hash (see
 * Server::sessionKey()), so changing the password revokes all tokens of that
 * user without any stored state.
 */
final class SessionToken
{
    public const PREFIX = 'u1.';

    public static function create(string $userId, int $expires, string $key): string
    {
        $payload = self::encode((string) json_encode(['u' => $userId, 'e' => $expires]));

        return self::PREFIX . $payload . '.' . self::encode(self::sign($payload, $key));
    }

    public static function looksLike(string $token): bool
    {
        return str_starts_with($token, self::PREFIX);
    }

    /**
     * Reads the user id without checking the signature — the caller needs it
     * to look up the key, then calls verify().
     */
    public static function userId(string $token): ?string
    {
        $data = self::payload($token);

        return is_string($data['u'] ?? null) === true ? $data['u'] : null;
    }

    public static function verify(string $token, string $key, ?int $now = null): bool
    {
        if (self::looksLike($token) === false) {
            return false;
        }

        $parts = explode('.', substr($token, strlen(self::PREFIX)));

        if (count($parts) !== 2) {
            return false;
        }

        [$payload, $signature] = $parts;

        if (hash_equals(self::encode(self::sign($payload, $key)), $signature) === false) {
            return false;
        }

        $expires = self::payload($token)['e'] ?? 0;

        return is_int($expires) === true && $expires >= ($now ?? time());
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $token): array
    {
        if (self::looksLike($token) === false) {
            return [];
        }

        $payload = explode('.', substr($token, strlen(self::PREFIX)))[0];
        $data    = json_decode((string) self::decode($payload), true);

        return is_array($data) === true ? $data : [];
    }

    private static function sign(string $payload, string $key): string
    {
        return hash_hmac('sha256', $payload, $key, true);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
