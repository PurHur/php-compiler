<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #271: $_COOKIE from HTTP_COOKIE / Cookie header.
 */
final class SuperglobalsCookieTest extends TestCase
{
    public function testPopulatesCookieFromHttpCookieEnv(): void
    {
        putenv('HTTP_COOKIE=session=abc123');
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', '');

        $cookie = $runtime->vmContext->getSuperglobal('_COOKIE');
        $this->assertNotNull($cookie);
        $table = $cookie->toArray();
        $this->assertSame('abc123', $table->find('session')->resolveIndirect()->toString());
    }

    public function testPopulatesMultipleCookiesWithUrlEncoding(): void
    {
        putenv('HTTP_COOKIE=theme%3Ddark; session=abc%20123');
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', '');

        $cookie = $runtime->vmContext->getSuperglobal('_COOKIE');
        $this->assertNotNull($cookie);
        $table = $cookie->toArray();
        $this->assertSame('dark', $table->find('theme')->resolveIndirect()->toString());
        $this->assertSame('abc 123', $table->find('session')->resolveIndirect()->toString());
    }

    public function testPopulateCookieDirectly(): void
    {
        $runtime = new Runtime();
        Superglobals::populateCookie($runtime->vmContext, 'lang=en');

        $cookie = $runtime->vmContext->getSuperglobal('_COOKIE');
        $this->assertNotNull($cookie);
        $this->assertSame('en', $cookie->toArray()->find('lang')->resolveIndirect()->toString());
    }
}
