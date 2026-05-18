<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #193: HTTP request headers mapped to $_SERVER HTTP_* keys.
 */
final class SuperglobalsHttpHeadersTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'CONTENT_')) {
                unset($_SERVER[$key]);
                putenv($key);
            }
        }
    }

    public function testHeaderNameToServerKey(): void
    {
        $this->assertSame('HTTP_HOST', Superglobals::headerNameToServerKey('Host'));
        $this->assertSame('HTTP_X_CUSTOM', Superglobals::headerNameToServerKey('X-Custom'));
        $this->assertSame('CONTENT_TYPE', Superglobals::headerNameToServerKey('Content-Type'));
        $this->assertSame('CONTENT_LENGTH', Superglobals::headerNameToServerKey('content-length'));
    }

    public function testApplyHttpHeadersSetsCgiEnvironment(): void
    {
        $cgi = Superglobals::applyHttpHeaders([
            'host' => 'example.test',
            'x-custom' => '1',
        ]);

        $this->assertSame([
            'HTTP_HOST' => 'example.test',
            'HTTP_X_CUSTOM' => '1',
        ], $cgi);
        $this->assertSame('example.test', $_SERVER['HTTP_HOST'] ?? '');
        $this->assertSame('1', getenv('HTTP_X_CUSTOM'));
    }
}
