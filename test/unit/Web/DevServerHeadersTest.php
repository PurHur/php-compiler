<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\DevServer;
use PHPUnit\Framework\TestCase;

final class DevServerHeadersTest extends TestCase
{
    public function testHeaderNameToServerKey(): void
    {
        $this->assertSame('HTTP_HOST', DevServer::headerNameToServerKey('host'));
        $this->assertSame('HTTP_X_CUSTOM', DevServer::headerNameToServerKey('x-custom'));
        $this->assertSame('HTTP_USER_AGENT', DevServer::headerNameToServerKey('User-Agent'));
    }

    public function testHttpHeadersToServerVars(): void
    {
        $vars = DevServer::httpHeadersToServerVars([
            'host' => 'example.test',
            'x-custom' => '1',
        ]);
        $this->assertSame([
            'HTTP_HOST' => 'example.test',
            'HTTP_X_CUSTOM' => '1',
        ], $vars);
    }

    public function testRejectsHeaderValueWithNewlines(): void
    {
        $vars = DevServer::httpHeadersToServerVars([
            'x-inject' => "evil\r\nSet-Cookie: bad=1",
        ]);
        $this->assertSame([], $vars);
    }

    public function testContentLengthForRequestUsesBodySize(): void
    {
        $body = 'abcdefghijkl';
        $this->assertSame('12', DevServer::contentLengthForRequest(['content-length' => '99'], $body));
        $this->assertNull(DevServer::contentLengthForRequest([], $body));
    }

    public function testParsePeerAddressIpv4(): void
    {
        $parsed = DevServer::parsePeerAddress('127.0.0.1:54321');
        $this->assertNotNull($parsed);
        $this->assertSame(['127.0.0.1', '54321'], $parsed);
    }

    public function testParsePeerAddressIpv6(): void
    {
        $parsed = DevServer::parsePeerAddress('[::1]:54321');
        $this->assertNotNull($parsed);
        $this->assertSame(['::1', '54321'], $parsed);
    }

    public function testParsePeerAddressRejectsInvalid(): void
    {
        $this->assertNull(DevServer::parsePeerAddress(''));
        $this->assertNull(DevServer::parsePeerAddress('no-port'));
        $this->assertNull(DevServer::parsePeerAddress('[::1]'));
    }
}
