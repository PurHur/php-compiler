<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/** CLI $_SERVER parity (#14209, #14210). */
final class SuperglobalsCliServerTest extends TestCase
{
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new Runtime();
    }

    protected function tearDown(): void
    {
        foreach (['REQUEST_METHOD', 'QUERY_STRING', 'REQUEST_BODY', 'SCRIPT_FILENAME'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    public function testEnvironEnumerateRequestMethod(): void
    {
        $environ = \PHPCompiler\ext\standard\VmEnvEnvironNative::enumerate();
        $this->assertArrayNotHasKey('REQUEST_METHOD', $environ);
    }

    public function testShouldPopulateCgiServerDefaultsBareCli(): void
    {
        $this->assertFalse(Superglobals::shouldPopulateCgiServerDefaults(null, null));
    }

    public function testBareCliLeavesRequestMethodUnset(): void
    {
        Superglobals::populateFromEnvironment($this->runtime->vmContext);

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertNull($server->find('REQUEST_METHOD'));
    }

    public function testBareCliMirrorsPathFromEnviron(): void
    {
        $environ = \PHPCompiler\ext\standard\VmEnvEnvironNative::enumerate();
        if (!isset($environ['PATH'])) {
            $this->markTestSkipped('PATH not in /proc/self/environ');
        }

        Superglobals::populateFromEnvironment($this->runtime->vmContext);

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $var = $server->find('PATH');
        $this->assertNotNull($var);
        $this->assertSame($environ['PATH'], $var->resolveIndirect()->toString());
    }
}
