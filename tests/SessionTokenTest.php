<?php

namespace BB\ContentPull\Tests;

use BB\ContentPull\SessionToken;
use PHPUnit\Framework\TestCase;

final class SessionTokenTest extends TestCase
{
    public function testValidToken(): void
    {
        $token = SessionToken::create('abc123', 2000, 'key');

        $this->assertTrue(SessionToken::looksLike($token));
        $this->assertSame('abc123', SessionToken::userId($token));
        $this->assertTrue(SessionToken::verify($token, 'key', now: 1000));
    }

    public function testExpiredToken(): void
    {
        $token = SessionToken::create('abc123', 2000, 'key');

        $this->assertTrue(SessionToken::verify($token, 'key', now: 2000));
        $this->assertFalse(SessionToken::verify($token, 'key', now: 2001));
    }

    public function testOtherKeyIsRejected(): void
    {
        // The key derives from the password hash: a new password, a new key
        $token = SessionToken::create('abc123', 2000, 'old password hash');

        $this->assertFalse(SessionToken::verify($token, 'new password hash', now: 1000));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $token = SessionToken::create('abc123', 2000, 'key');
        [, $payload, $signature] = explode('.', $token);

        // Same signature, payload claiming another user and a later expiry
        $forged  = rtrim(strtr(base64_encode((string) json_encode(['u' => 'admin', 'e' => 9999999999])), '+/', '-_'), '=');
        $tampered = SessionToken::PREFIX . $forged . '.' . $signature;

        $this->assertSame('admin', SessionToken::userId($tampered));
        $this->assertFalse(SessionToken::verify($tampered, 'key', now: 1000));
    }

    public function testGarbage(): void
    {
        $this->assertFalse(SessionToken::looksLike('a-shared-token'));
        $this->assertNull(SessionToken::userId('a-shared-token'));
        $this->assertFalse(SessionToken::verify('a-shared-token', 'key'));
        $this->assertFalse(SessionToken::verify('u1.only-one-part', 'key'));
        $this->assertNull(SessionToken::userId('u1.!!!.???'));
    }
}
