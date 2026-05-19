<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #235: REQUEST_SCHEME, HTTPS, SERVER_PORT, SERVER_NAME for absolute URLs.
 */
final class SuperglobalsUrlSchemeTest extends TestCase
{
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new Runtime();
    }

    protected function tearDown(): void
    {
        foreach (['HTTPS', 'HTTP_HOST', 'HTTP_X_FORWARDED_PROTO', 'SERVER_PORT'] as $key) {
            putenv($key);
            unset($_SERVER[$key]);
        }
    }

    public function testHttpsFromForwardedProto(): void
    {
        putenv('HTTP_HOST=example.test');
        putenv('HTTP_X_FORWARDED_PROTO=https');
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('https', $this->readServer($server, 'REQUEST_SCHEME'));
        $this->assertSame('on', $this->readServer($server, 'HTTPS'));
        $this->assertSame('443', $this->readServer($server, 'SERVER_PORT'));
        $this->assertSame('example.test', $this->readServer($server, 'SERVER_NAME'));
    }

    public function testHttpDefaultPort(): void
    {
        putenv('HTTP_HOST=example.test');
        $_SERVER['HTTP_HOST'] = 'example.test';

        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('http', $this->readServer($server, 'REQUEST_SCHEME'));
        $this->assertSame('', $this->readServer($server, 'HTTPS'));
        $this->assertSame('80', $this->readServer($server, 'SERVER_PORT'));
    }

    public function testParseHostAndPort(): void
    {
        $this->assertSame(['example.test', 8080], Superglobals::parseHostAndPort('example.test:8080'));
        $this->assertSame(['example.test', null], Superglobals::parseHostAndPort('example.test'));
        $this->assertSame(['::1', 443], Superglobals::parseHostAndPort('[::1]:443'));
    }

    public function testAbsoluteUrlParts(): void
    {
        putenv('HTTP_HOST=example.test');
        putenv('HTTP_X_FORWARDED_PROTO=https');
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $url = $this->readServer($server, 'REQUEST_SCHEME')
            .'://'
            .$this->readServer($server, 'HTTP_HOST');
        $this->assertSame('https://example.test', $url);
    }

    private function readServer(\PHPCompiler\VM\HashTable $server, string $key): string
    {
        $var = $server->find($key);
        if (null === $var) {
            return '';
        }
        $resolved = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_STRING !== $resolved->type) {
            return '';
        }

        return $resolved->toString();
    }
}
