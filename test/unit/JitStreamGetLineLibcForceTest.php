<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT must force libc stream_get_line after NestedJIT (#34835).
 *
 * ensureLinked alone still wires VmFs NestedJIT, which cannot see
 * JitStreamIoKernel FILE* handles — peer fgets force (#27663 / #33133).
 */
final class JitStreamGetLineLibcForceTest extends TestCase
{
    public function testForceLibcStreamPositionAbisCallsStreamGetLineForce(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#34835', $src);
        $this->assertStringContainsString('implementStreamGetLineForce($context)', $src);
        $forcePos = strpos($src, 'function forceLibcStreamPositionAbis');
        $sglPos = strpos($src, 'implementStreamGetLineForce($context)');
        $this->assertNotFalse($forcePos);
        $this->assertNotFalse($sglPos);
        $this->assertGreaterThan($forcePos, $sglPos);
    }

    public function testJitStreamIoKernelEmitsLibcStreamGetLine(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('function implementStreamGetLineForce', $src);
        $this->assertStringContainsString('sgl_entry', $src);
        $this->assertStringContainsString('#34835', $src);
    }

    public function testNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/stream_get_line.c');
        $this->assertFileDoesNotExist($root.'/runtime/stream_get_line.c');
    }
}
