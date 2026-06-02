<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile verify for pipe operator (|>) after desugar (#4456).
 *
 * @group llvm
 */
final class PipeOperatorJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — pipe operator JIT compile needs LLVM (#4456)');
        }
    }

    public function testPipeWithFirstClassCallableCompilesToStrtoupperCall(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
echo "hi" |> strtoupper(...);
PHP
            ,
            'pipe_jit_compile.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $foundLiteral = false;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT !== $op->type) {
                continue;
            }
            $nameOp = $block->getOperand($op->arg1);
            if ($nameOp instanceof \PHPCfg\Operand\Literal && 'strtoupper' === $nameOp->value) {
                $foundLiteral = true;
            }
        }
        $this->assertTrue($foundLiteral, 'expected FUNCCALL_INIT to fold to literal strtoupper after desugar');
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
