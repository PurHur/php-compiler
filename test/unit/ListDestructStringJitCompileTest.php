<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../LlvmToolchain.php';

/**
 * JIT lowering for list destructuring from string RHS (#4308).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class ListDestructStringJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason . ' — list destruct string JIT compile test needs LLVM (#4308)');
        }
    }

    public function testListDestructStringModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
[$a] = 'ab';
echo $a === null ? 'null' : $a;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'list_destructure_string.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        self::addToAssertionCount(1);
    }
}
