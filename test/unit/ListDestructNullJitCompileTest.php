<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../LlvmToolchain.php';

/**
 * JIT lowering for list destructuring from non-array RHS (#4325).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class ListDestructNullJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason . ' — list destruct null JIT compile test needs LLVM (#4325)');
        }
    }

    public function testListDestructNullModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
[$a, $b] = null;
echo "a=", var_export($a, true), " b=", var_export($b, true), "\n";
list($x) = false;
echo "x=", var_export($x, true), "\n";
[$y] = 0;
echo "y=", var_export($y, true), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'list_destructure_null.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        self::addToAssertionCount(1);
    }
}
