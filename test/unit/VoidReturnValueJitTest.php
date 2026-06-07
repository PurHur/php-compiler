<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * JIT/AOT must reject TYPE_RETURN with a value in :void functions (#4836).
 *
 * php-src: Zend/zend_compile.c void return prohibition, Zend/zend_execute.c ZEND_RETURN
 *
 * @covers issue #4836
 *
 * @group llvm
 */
final class VoidReturnValueJitTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testVmRejectsSyntheticValueReturnInVoidFunction(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function f(): void {
    $x = 1;
    return;
}
try {
    f();
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP,
            'void_return_value_vm.php'
        );
        $voidBlock = $this->findVoidFunctionBlock($block);
        $this->assertNotNull($voidBlock);
        $this->injectSyntheticValueReturn($voidBlock);

        ob_start();
        $exit = $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(VM::SUCCESS, $exit);
        $this->assertStringContainsString('A void function must not return a value', $output);
        $this->assertStringNotContainsString('no throw', $output);
    }

    public function testVoidReturnValueLoweringVerifiesOnJitCompile(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — void return JIT compile test needs LLVM (#4836)');
        }

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function f(): void {
    $x = 1;
    return;
}
f();
PHP,
            'void_return_value_jit.php'
        );
        $voidBlock = $this->findVoidFunctionBlock($block);
        $this->assertNotNull($voidBlock);
        $this->injectSyntheticValueReturn($voidBlock);

        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    private function findVoidFunctionBlock(Block $block): ?Block
    {
        if ($block->returnTypeVoid && null !== $block->func && '{main}' !== $block->func->name) {
            return $block;
        }
        foreach ($block->opCodes as $op) {
            foreach (['block1', 'block2', 'block3'] as $key) {
                if (isset($op->{$key}) && $op->{$key} instanceof Block) {
                    $found = $this->findVoidFunctionBlock($op->{$key});
                    if (null !== $found) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    private function injectSyntheticValueReturn(Block $voidBlock): void
    {
        $valueSlot = null;
        foreach ($voidBlock->opCodes as $op) {
            if (OpCode::TYPE_RETURN_VOID === $op->type) {
                $this->assertNotNull($valueSlot, 'Expected assignment before void return');
                $op->type = OpCode::TYPE_RETURN;
                $op->arg1 = $valueSlot;

                return;
            }
            if (OpCode::TYPE_ASSIGN === $op->type && null !== $op->arg1) {
                $valueSlot = $op->arg1;
            }
        }

        $this->fail('Expected TYPE_RETURN_VOID in void function block');
    }
}
