<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Support\Cookie;
use Tests\NafTestCase;

final class CookieTest extends NafTestCase
{
    public function testDefaultParametersProduceSecureDefaults(): void
    {
        $cookie = new Cookie('theme', 'dark');

        self::assertSame('theme', $cookie->getName());
        self::assertSame('dark', $cookie->getValue());
        self::assertSame(0, $cookie->getExpire());
        self::assertSame('/', $cookie->getPath());
        self::assertSame('', $cookie->getDomain());
        self::assertFalse($cookie->isSecure());
        self::assertTrue($cookie->isHttponly());
        self::assertSame('Lax', $cookie->getSamesite());
        self::assertSame('theme=dark; Path=/; HttpOnly; SameSite=Lax', (string) $cookie);
    }

    public function testFullCookieFormattingAndUrlEncoding(): void
    {
        $expire = time() + 3600;
        $cookie = new Cookie(
            name: 'user name',
            value: 'Ada+Lovelace',
            expire: $expire,
            path: '/app',
            domain: 'example.test',
            secure: true,
            httponly: true,
            samesite: 'strict',
        );

        $header = (string) $cookie;

        self::assertStringStartsWith('user%20name=Ada%2BLovelace; ', $header);
        self::assertStringContainsString('Expires=' . gmdate('D, d-M-Y H:i:s T', $expire), $header);
        self::assertStringContainsString('Max-Age=', $header);
        self::assertStringContainsString('Path=/app; Domain=example.test; Secure; HttpOnly; SameSite=Strict', $header);
    }

    public function testNegativeExpirationExpiresCookieImmediately(): void
    {
        $cookie = new Cookie('expired', '', -1);

        self::assertStringContainsString('Expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0', (string) $cookie);
    }

    public function testFromHeaderParsesAndDecodesCookiesConsistently(): void
    {
        self::assertSame([], Cookie::fromHeader('   '));

        $cookies = Cookie::fromHeader('user%20name=Ada%2BLovelace; lang=de; =ignored; empty=');

        self::assertCount(3, $cookies);
        self::assertArrayHasKey('user name', $cookies);
        self::assertSame('user name', $cookies['user name']->getName());
        self::assertSame('Ada+Lovelace', $cookies['user name']->getValue());
        self::assertSame('de', $cookies['lang']->getValue());
        self::assertSame('', $cookies['empty']->getValue());
    }
}
