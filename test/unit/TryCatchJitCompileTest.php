<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * LLVM compile-only smoke for try/catch JIT lowering (#1056, #2084).
 *
 * @group llvm
 */
final class TryCatchJitCompileTest extends TestCase
{
    public function testTryCatchJitModuleVerifies(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $code = <<<'PHP'
<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "caught\n";
}
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'try_catch_jit_smoke.php');
        $runtime->jit($block);
        $this->addToAssertionCount(1);
    }
}
