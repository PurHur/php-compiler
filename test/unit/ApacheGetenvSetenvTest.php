<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\BuiltinRegistry;
use PHPCompiler\ext\standard\VmHead;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** apache_getenv()/apache_setenv() SAPI gating and environ round-trip (#11626). */
final class ApacheGetenvSetenvTest extends TestCase
{
    private string|false|null $savedRequestMethod = null;

    protected function setUp(): void
    {
        $this->savedRequestMethod = getenv('REQUEST_METHOD');
        if (false !== $this->savedRequestMethod) {
            putenv('REQUEST_METHOD');
            unset($_ENV['REQUEST_METHOD'], $_SERVER['REQUEST_METHOD']);
        }
    }

    protected function tearDown(): void
    {
        if (false !== $this->savedRequestMethod && null !== $this->savedRequestMethod) {
            putenv('REQUEST_METHOD='.$this->savedRequestMethod);
            $_ENV['REQUEST_METHOD'] = $this->savedRequestMethod;
            $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
        } else {
            putenv('REQUEST_METHOD');
            unset($_ENV['REQUEST_METHOD'], $_SERVER['REQUEST_METHOD']);
        }
        BuiltinRegistry::resetForTest();
    }

    public function testPlainCliDoesNotRegisterApacheEnvironmentFunctions(): void
    {
        $this->assertFalse(VmHead::registersRequestHeaderFunctions());
        $this->assertNotContains('apache_getenv', BuiltinRegistry::sortedNames());
        $this->assertNotContains('apache_setenv', BuiltinRegistry::sortedNames());

        $runtime = new Runtime();
        $this->assertArrayNotHasKey('apache_getenv', $runtime->vmContext->functions);
        $this->assertArrayNotHasKey('apache_setenv', $runtime->vmContext->functions);
    }

    public function testCgiRequestMethodRegistersApacheEnvironmentFunctions(): void
    {
        putenv('REQUEST_METHOD=GET');
        $_ENV['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertTrue(VmHead::registersRequestHeaderFunctions());

        $runtime = new Runtime();
        $this->assertArrayHasKey('apache_getenv', $runtime->vmContext->functions);
        $this->assertArrayHasKey('apache_setenv', $runtime->vmContext->functions);
    }

    public function testReproRoundTripOnCgiProfile(): void
    {
        putenv('REQUEST_METHOD=GET');
        $_ENV['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $repoRoot = \dirname(__DIR__, 2);
        $script = $repoRoot.'/test/repro/maintainer_gap_apache_getenv_setenv.php';
        $cmd = 'REQUEST_METHOD=GET '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($repoRoot.'/bin/vm.php').' '
            .escapeshellarg($script).' 2>/dev/null';
        $output = shell_exec($cmd);
        $this->assertSame("ok\n", $output);
    }
}
