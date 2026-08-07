<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** sem_* JIT routes through SemRuntime LLVM libc (#28431). */
final class SemRuntimeShrinkTest extends TestCase
{
    public function testSemGetCallUsesJitSemGet(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvsem/sem_get.php');
        $this->assertStringContainsString('JitSemGet::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testSemAcquireCallUsesJitSemAcquire(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvsem/sem_acquire.php');
        $this->assertStringContainsString('JitSemAcquire::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testSemReleaseCallUsesJitSemRelease(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvsem/sem_release.php');
        $this->assertStringContainsString('JitSemRelease::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testSemRemoveCallUsesJitSemRemove(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvsem/sem_remove.php');
        $this->assertStringContainsString('JitSemRemove::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testSemRuntimeIsPureLlvmNoNestedJitMap(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SemRuntime.php');
        $this->assertStringContainsString('__compiler_sem_get_register', $source);
        $this->assertStringContainsString('semget', $source);
        $this->assertStringContainsString('semop', $source);
        $this->assertStringContainsString('semctl', $source);
        $this->assertStringContainsString('__compiler_sem_owned_map', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSemHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SemJitHelper.php', $spine);
        $this->assertStringContainsString('SemLibcThinAbi.php', $spine);
        $this->assertStringContainsString('JitSemGet.php', $spine);
        $this->assertStringContainsString('SemRuntime.php', $spine);
        $this->assertStringContainsString('StringSem.php', $spine);
    }
}
