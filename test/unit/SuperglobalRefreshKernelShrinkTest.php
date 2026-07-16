<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** User-script AOT superglobal refresh kernel quarantined in ext/standard (#19512, #13717). */
final class SuperglobalRefreshKernelShrinkTest extends TestCase
{
    public function testBuiltinUserScriptLlvmDeletedAndExtKernelExists(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSuperglobalRefreshKernel.php');

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalRefreshKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitSuperglobalRefreshKernel', $kernel);
        $this->assertStringContainsString('__superglobals__refresh', $kernel);
        $this->assertStringContainsString('ParseStrRuntime::ensureUserScriptLinked', $kernel);
        $this->assertStringContainsString('MultipartRuntime::ensureUserScriptLinked', $kernel);
        $this->assertStringContainsString('EnvironMirrorRuntime::ensureLinked', $kernel);
    }

    public function testRuntimeDelegatesToExtKernel(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('use PHPCompiler\\ext\\standard\\JitSuperglobalRefreshKernel;', $runtime);
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::implement', $runtime);
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::ensurePrerequisites', $runtime);
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::ensureDeferredEmitPrerequisites', $runtime);
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::emitRefresh', $runtime);
        $this->assertStringNotContainsString('SuperglobalRefreshUserScriptLlvm', $runtime);
    }

    public function testSpineIncludesKernelNotBuiltinUserScriptLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSuperglobalRefreshKernel.php', $spine);
        $this->assertStringContainsString('posix_initgroups.php', $spine);
        $this->assertStringNotContainsString('SuperglobalRefreshUserScriptLlvm.php', $spine);
    }
}
