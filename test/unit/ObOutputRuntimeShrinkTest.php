<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ObOutputJitHelper;
use PHPUnit\Framework\TestCase;

/** ObOutputRuntime routes standalone + embed through ObOutputJitHelper PHP, not LLVM buffer globals (#9268, #12951). */
final class ObOutputRuntimeShrinkTest extends TestCase
{
    public function testObOutputRuntimeUsesHelperNotLlvmStack(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputRuntime.php');
        $this->assertStringContainsString('ObOutputJitBridge::implement', $runtime);
        $this->assertStringNotContainsString('ObOutputStandaloneLlvm', $runtime);
        $runtimeLines = \substr_count($runtime, "\n") + 1;
        $this->assertLessThan(25, $runtimeLines);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ObOutputStandaloneLlvm.php');

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputJitBridge.php');
        $this->assertStringContainsString('ObOutputJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('ObStorageGlobals::ensureGlobals', $bridge);
        $this->assertStringNotContainsString('GLOBAL_STORAGE', $bridge);
        $this->assertStringNotContainsString('implementPopBuffer', $bridge);
        $this->assertStringNotContainsString('ObOutputStandaloneLlvm', $bridge);
        $bridgeLines = \substr_count($bridge, "\n") + 1;
        $this->assertLessThan(800, $bridgeLines);
        // #12974: list-spread destructuring in foreach breaks self-host parseAndCompile.
        $this->assertDoesNotMatchRegularExpression(
            '/as\s+\$[a-zA-Z_]+\s*=>\s*\[\$[a-zA-Z_]+,\s*\$[a-zA-Z_]+,\s*\.\.\.\$/',
            $bridge
        );
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ObOutputJitHelper.php');
        $this->assertStringNotContainsString('use PHPCompiler\VM\ObStackLimits', $helper);
        $this->assertStringNotContainsString('ObStackLimits::BUF_SIZE', $helper);
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
