<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\DevServer;
use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #314: $_SERVER['CONTENT_LENGTH'] from HTTP Content-Length header.
 */
final class SuperglobalsContentLengthTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CONTENT_LENGTH');
        foreach (array_keys($_SERVER) as $key) {
            if ('CONTENT_LENGTH' === $key) {
                unset($_SERVER[$key]);
            }
        }
    }

    public function testContentLengthForRequestUsesReadBodySize(): void
    {
        $body = 'abcdefghijkl';
        $this->assertSame('12', DevServer::contentLengthForRequest(['content-length' => '12'], $body));
    }

    public function testContentLengthForRequestAbsentWithoutHeader(): void
    {
        $this->assertNull(DevServer::contentLengthForRequest([], 'body'));
    }

    public function testApplyHttpHeadersMapsContentLength(): void
    {
        $cgi = Superglobals::applyHttpHeaders(['content-length' => '12']);
        $this->assertSame(['CONTENT_LENGTH' => '12'], $cgi);
        $this->assertSame('12', $_SERVER['CONTENT_LENGTH'] ?? '');
    }

    public function testPopulateFromEnvironmentReadsContentLength(): void
    {
        putenv('CONTENT_LENGTH=12');
        $_SERVER['CONTENT_LENGTH'] = '12';
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', 'abcdefghijkl');

        $server = $runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $var = $server->find('CONTENT_LENGTH');
        $this->assertNotNull($var);
        $this->assertSame('12', $var->resolveIndirect()->toString());
    }
}
