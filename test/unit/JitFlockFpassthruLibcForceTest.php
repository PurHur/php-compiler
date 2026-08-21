<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT must force libc flock/fpassthru after NestedJIT (#33122).
 *
 * ensureLinked alone (#33115) still wires VmFs NestedJIT, which cannot see
 * JitStreamIoKernel FILE* handles — peer fgets/fseek force (#27663).
 */
final class JitFlockFpassthruLibcForceTest extends TestCase
{
    public function testForceLibcStreamPositionAbisCallsFlockAndFpassthru(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33122', $src);
        $this->assertStringContainsString('implementFlockForce($context)', $src);
        $this->assertStringContainsString('implementFpassthruForce($context)', $src);
        $forcePos = strpos($src, 'function forceLibcStreamPositionAbis');
        $flockPos = strpos($src, 'implementFlockForce($context)');
        $fpPos = strpos($src, 'implementFpassthruForce($context)');
        $this->assertNotFalse($forcePos);
        $this->assertNotFalse($flockPos);
        $this->assertNotFalse($fpPos);
        $this->assertGreaterThan($forcePos, $flockPos);
        $this->assertGreaterThan($forcePos, $fpPos);
    }

    public function testJitStreamIoKernelEmitsLibcFlockWithPhpLockMap(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('function implementFlockForce', $src);
        $this->assertStringContainsString('function implementFpassthruForce', $src);
        $this->assertStringContainsString('flock_entry', $src);
        $this->assertStringContainsString('fpassthru_entry', $src);
        $this->assertStringContainsString('PHP LOCK_UN=3', $src);
        $this->assertStringContainsString('#33122', $src);
    }

    public function testNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/flock.c');
        $this->assertFileDoesNotExist($root.'/runtime/flock.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/fpassthru.c');
        $this->assertFileDoesNotExist($root.'/runtime/fpassthru.c');
    }
}
