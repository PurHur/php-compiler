<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;
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
        putenv('CONTENT_TYPE');
    }

    public function testHeaderNameToServerKey(): void
    {
        $this->assertSame('HTTP_HOST', Superglobals::headerNameToServerKey('Host'));
        $this->assertSame('HTTP_X_CUSTOM', Superglobals::headerNameToServerKey('X-Custom'));
        $this->assertSame('CONTENT_TYPE', Superglobals::headerNameToServerKey('Content-Type'));
        $this->assertSame('CONTENT_LENGTH', Superglobals::headerNameToServerKey('content-length'));
    }

    public function testServerKeyToHeaderName(): void
    {
        $this->assertSame('Host', Superglobals::serverKeyToHeaderName('HTTP_HOST'));
        $this->assertSame('X-Test', Superglobals::serverKeyToHeaderName('HTTP_X_TEST'));
        $this->assertSame('User-Agent', Superglobals::serverKeyToHeaderName('HTTP_USER_AGENT'));
        $this->assertSame('Content-Type', Superglobals::serverKeyToHeaderName('CONTENT_TYPE'));
        $this->assertSame('Content-Length', Superglobals::serverKeyToHeaderName('CONTENT_LENGTH'));
        $this->assertNull(Superglobals::serverKeyToHeaderName('REQUEST_METHOD'));
    }

    public function testCollectRequestHeadersFromEnvironment(): void
    {
        putenv('HTTP_X_TEST=1');
        putenv('HTTP_HOST=example.test');
        $_SERVER['HTTP_X_TEST'] = '1';
        $_SERVER['HTTP_HOST'] = 'example.test';

        $headers = Superglobals::collectRequestHeaders();

        $this->assertSame('1', $headers['X-Test'] ?? '');
        $this->assertSame('example.test', $headers['Host'] ?? '');
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

    public function testGetallheadersBuiltinHashTableIsReadable(): void
    {
        putenv('REQUEST_METHOD=GET');
        $_ENV['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        putenv('HTTP_X_TEST=1');
        putenv('HTTP_HOST=example.test');
        $_SERVER['HTTP_X_TEST'] = '1';
        $_SERVER['HTTP_HOST'] = 'example.test';

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', '');

        $fn = $runtime->vmContext->functions['getallheaders'] ?? null;
        $this->assertNotNull($fn);

        $returnVar = new Variable();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = $returnVar;
        $fn->execute($frame);

        $table = $returnVar->resolveIndirect()->toArray();
        $this->assertSame('1', $table->find('X-Test')->resolveIndirect()->toString());
        $this->assertSame('example.test', $table->find('Host')->resolveIndirect()->toString());
    }

    public function testCollectRequestHeadersIncludesContentType(): void
    {
        putenv('CONTENT_TYPE=application/json');
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $headers = Superglobals::collectRequestHeaders();

        $this->assertSame('application/json', $headers['Content-Type'] ?? '');
    }
}
