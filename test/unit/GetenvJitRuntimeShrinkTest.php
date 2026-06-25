<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getenv/putenv JIT overlay routes through GetenvJitHelper PHP (#9092, #8992). */
final class GetenvJitRuntimeShrinkTest extends TestCase
{
    public function testGetenvJitHelperHasOverlayStorage(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetenvJitHelper.php');
        $this->assertStringContainsString('private static array $local', $source);
        $this->assertStringContainsString('parseAssignment', $source);
    }

    public function testStringGetenvRoutesThroughGetenvJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('GetenvJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringEnvLocal.php');
        $this->assertStringNotContainsString('__compiler_env_local_lookup', $source);
        $this->assertStringNotContainsString("lookupFunction('getenv')", $source);
    }

    public function testJitEnvPutenvUsesGetenvJitHelperOverlayOnly(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString('GetenvJitHelper::putenv', $source);
        $this->assertStringNotContainsString("lookupFunction('putenv')", $source);
    }

    public function testGetenvJitHelperUsesEnvironNative(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetenvJitHelper.php');
        $this->assertStringContainsString('VmEnvEnvironNative::enumerate()', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\FFI/', $source);
    }

    public function testVmEnvExportsAllEnvironmentMap(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmEnv.php');
        $this->assertStringContainsString('exportAllEnvironmentMap', $source);
    }

    public function testGetenvJitHelperOverlayRoundtripOnHost(): void
    {
        $key = 'PHPC_GETENV_JIT_HELPER_'.(string) \getmypid();
        $this->assertTrue(\PHPCompiler\ext\standard\GetenvJitHelper::putenv($key.'=probe'));
        $this->assertSame('probe', \PHPCompiler\ext\standard\GetenvJitHelper::getenv($key, 0));
        $this->assertTrue(\PHPCompiler\ext\standard\GetenvJitHelper::putenv($key));
        $this->assertFalse(\PHPCompiler\ext\standard\GetenvJitHelper::getenv($key, 0));
    }

    public function testGetenvJitHelperRejectsInvalidPutenvSyntax(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(\PHPCompiler\ext\standard\VmEnv::PUTENV_INVALID_SYNTAX_ERROR);
        \PHPCompiler\ext\standard\GetenvJitHelper::putenv('=invalid');
    }
}
