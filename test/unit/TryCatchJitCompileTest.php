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

    /** Issue #3012: try/catch dispatch BB must not leak across queued CFG functions. */
    public function testTryCatchDispatchNotSharedAcrossFunctions(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $code = <<<'PHP'
<?php
function note_progress(): void
{
    try {
        echo "ok\n";
    } catch (\Throwable $e) {
    }
}
function dispatch(): void
{
    throw new \RuntimeException('uncaught in second function');
}
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'try_catch_cross_func.php');
        $runtime->jit($block);
        $this->addToAssertionCount(1);
    }
}
