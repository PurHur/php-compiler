<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #295: $_SERVER['REMOTE_ADDR'] and REMOTE_PORT from CGI env.
 */
final class SuperglobalsRemoteAddrTest extends TestCase
{
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new Runtime();
    }

    protected function tearDown(): void
    {
        putenv('REMOTE_ADDR');
        putenv('REMOTE_PORT');
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['REMOTE_PORT']);
    }

    public function testRemoteAddrAndPortFromEnvironment(): void
    {
        putenv('REMOTE_ADDR=203.0.113.7');
        putenv('REMOTE_PORT=54321');

        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('203.0.113.7', $this->readServer($server, 'REMOTE_ADDR'));
        $this->assertSame('54321', $this->readServer($server, 'REMOTE_PORT'));
    }

    public function testRemoteAddrOmittedWhenUnset(): void
    {
        putenv('REMOTE_ADDR');
        putenv('REMOTE_PORT');

        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('', $this->readServer($server, 'REMOTE_ADDR'));
        $this->assertSame('', $this->readServer($server, 'REMOTE_PORT'));
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
