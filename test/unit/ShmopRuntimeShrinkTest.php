<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** shmop_* JIT routes through ShmopRuntime LLVM libc (#27408 / #28433). */
final class ShmopRuntimeShrinkTest extends TestCase
{
    public function testShmopOpenCallUsesJitShmopOpen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvshm/shmop_open.php');
        $this->assertStringContainsString('JitShmopOpen::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testShmopCloseCallUsesJitShmopClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvshm/shmop_close.php');
        $this->assertStringContainsString('JitShmopClose::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testShmopDeleteCallUsesJitShmopDelete(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvshm/shmop_delete.php');
        $this->assertStringContainsString('JitShmopDelete::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testShmopRuntimeIsPureLlvmNoNestedJitMap(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ShmopRuntime.php');
        $this->assertStringContainsString('__compiler_shmop_open_register', $source);
        $this->assertStringContainsString('shmget', $source);
        $this->assertStringContainsString('shmat', $source);
        $this->assertStringContainsString('memcpy', $source);
        $this->assertStringContainsString('__compiler_shmop_owned_map', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesShmopHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ShmopJitHelper.php', $spine);
        $this->assertStringContainsString('ShmopLibcThinAbi.php', $spine);
        $this->assertStringContainsString('JitShmopOpen.php', $spine);
        $this->assertStringContainsString('ShmopRuntime.php', $spine);
        $this->assertStringContainsString('StringShmop.php', $spine);
    }
}
