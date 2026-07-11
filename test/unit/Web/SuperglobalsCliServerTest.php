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
        foreach (['REQUEST_METHOD', 'QUERY_STRING', 'REQUEST_BODY', 'SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF'] as $key) {
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

    public function testCliScriptNamePopulatedFromExport(): void
    {
        Superglobals::populateFromEnvironment(
            $this->runtime->vmContext,
            null,
            null,
            '/var/www/html/index.php',
            'index.php'
        );

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('index.php', $this->readServer($server, 'SCRIPT_NAME'));
        $this->assertSame('index.php', $this->readServer($server, 'PHP_SELF'));
        $this->assertSame('/var/www/html/index.php', $this->readServer($server, 'SCRIPT_FILENAME'));
    }

    public function testCliVirtualScriptNameUsesStandardInputCode(): void
    {
        Superglobals::populateFromEnvironment(
            $this->runtime->vmContext,
            null,
            null,
            null,
            'Standard input code'
        );

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('Standard input code', $this->readServer($server, 'SCRIPT_NAME'));
        $this->assertNull($server->find('SCRIPT_FILENAME'));
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
