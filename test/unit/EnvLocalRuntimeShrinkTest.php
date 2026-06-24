<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** EnvLocalRuntime routes overlay through EnvLocalJitHelper/GetenvJitHelper PHP (#9814). */
final class EnvLocalRuntimeShrinkTest extends TestCase
{
    public function testStringEnvLocalDeletedAndEnvLocalRuntimeUsesJitHelper(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringEnvLocal.php');
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertStringContainsString('EnvLocalJitHelper', $source);
        $this->assertStringContainsString('GetenvJitHelper', $source);
        $this->assertStringNotContainsString("getNamedGlobal('phpc_env_local_entries')", $source);
        $this->assertStringNotContainsString("getNamedGlobal('phpc_env_local_count')", $source);
    }

    public function testStringGetenvAllUsesEnvLocalRuntimeMergeOverlay(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenvAll.php');
        $this->assertStringContainsString('EnvLocalRuntime::emitMergeOverlay', $source);
        $this->assertStringNotContainsString('emitLocalOverlay', $source);
        $this->assertStringNotContainsString('phpc_env_local_entries', $source);
    }

    public function testEnvLocalJitHelperDelegatesToGetenvJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/EnvLocalJitHelper.php');
        $this->assertStringContainsString('GetenvJitHelper::getenv', $source);
        $this->assertStringContainsString('GetenvJitHelper::putenv', $source);
        $this->assertStringContainsString('GetenvJitHelper::getAllEnvironmentMap', $source);
    }
}
