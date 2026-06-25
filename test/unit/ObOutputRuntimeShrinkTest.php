<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ObOutputJitHelper;
use PHPUnit\Framework\TestCase;

/** ObOutputRuntime must route through ObOutputJitHelper PHP, not LLVM buffer globals (#9268). */
final class ObOutputRuntimeShrinkTest extends TestCase
{
    public function testObOutputRuntimeUsesHelperNotLlvmStack(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputRuntime.php');
        $this->assertStringContainsString('ObOutputJitBridge::implement', $runtime);
        $this->assertStringContainsString('ObOutputStandaloneLlvm::implement', $runtime);
        $runtimeLines = \substr_count($runtime, "\n") + 1;
        $this->assertLessThan(35, $runtimeLines);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputJitBridge.php');
        $this->assertStringContainsString('ObOutputJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('ObStorageGlobals::ensureGlobals', $bridge);
        $this->assertStringNotContainsString('GLOBAL_STORAGE', $bridge);
        $this->assertStringNotContainsString('implementPopBuffer', $bridge);
        $bridgeLines = \substr_count($bridge, "\n") + 1;
        $this->assertLessThan(785, $bridgeLines);
        $this->assertGreaterThan(300, 1087 - $bridgeLines);
    }

    public function testObOutputJitHelperStackSemantics(): void
    {
        ObOutputJitHelper::reset();
        $this->assertSame(0, ObOutputJitHelper::getLevel());
        ObOutputJitHelper::start();
        ObOutputJitHelper::appendString('hello');
        $this->assertSame(1, ObOutputJitHelper::getLevel());
        $this->assertSame('hello', ObOutputJitHelper::getContents());
        $this->assertSame(5, ObOutputJitHelper::getLength());
        $this->assertSame(1, ObOutputJitHelper::endClean());
        $this->assertSame(0, ObOutputJitHelper::getLevel());
    }

    public function testObOutputJitHelperNestedBuffers(): void
    {
        ObOutputJitHelper::reset();
        ObOutputJitHelper::start();
        ObOutputJitHelper::start();
        ObOutputJitHelper::appendString('x');
        $this->assertSame(2, ObOutputJitHelper::getLevel());
        $this->assertSame(1, ObOutputJitHelper::endFlush());
        $this->assertSame(1, ObOutputJitHelper::getLevel());
        $this->assertSame('x', ObOutputJitHelper::getContents());
    }
}
