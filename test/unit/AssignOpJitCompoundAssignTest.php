<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** AssignOp peephole + JIT compound-assign lowering for nested helper compile (#13062). */
final class AssignOpJitCompoundAssignTest extends TestCase
{
    public function testAssignOpPeepholeSetsInPlaceCompoundSlots(): void
    {
        $code = <<<'PHP'
<?php
function f(int $inc): int {
    $outputPos = 0;
    if ($inc > 0) {
        $outputPos += $inc;
    }
    return $outputPos;
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'compound.php');
        $this->assertNotNull($block);
        $plus = $this->findTypePlusInFunction($block, 'f');
        $this->assertNotNull($plus);
        $this->assertSame($plus->arg1, $plus->arg2, 'in-place += should share dest/read slots');
        $this->assertNotNull($plus->arg3);
    }

    /** Issue #13106: AssignOp peephole must not clobber concat operands on simple assign RHS. */
    public function testSimpleAssignConcatRhs(): void
    {
        $this->assertVmOutput(
            file_get_contents(__DIR__ . '/../repro/maintainer_gap_assign_concat_rhs.php'),
            "ok literal concat assign\n"
            . "ok func concat assign\n"
            . "ok pid concat assign\n"
            . "ok int concat assign\n"
        );
    }

    public function testNestedJitCompileCompoundAssignHelper(): void
    {
        $code = <<<'PHP'
<?php
final class Helper {
    public static function bump(int $inc): int {
        $n = 0;
        $n += $inc;
        return $n;
    }
}
PHP;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile($code, 'Helper.php');
        $this->assertNotNull($block);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        $jit = new JIT($ctx);
        $jit->compile($block);
        $this->assertNotEmpty($ctx->functions);
    }

    private function findTypePlusInFunction(\PHPCompiler\Block $block, string $name): ?OpCode
    {
        $func = $this->findFuncBlock($block, $name);
        if (null === $func) {
            return null;
        }
        $queue = [$func];
        $seen = new \SplObjectStorage();
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ($seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            foreach ($current->opCodes as $op) {
                if (OpCode::TYPE_PLUS === $op->type) {
                    return $op;
                }
                if (null !== $op->block1) {
                    $queue[] = $op->block1;
                }
                if (null !== $op->block2) {
                    $queue[] = $op->block2;
                }
            }
        }

        return null;
    }

    private function findFuncBlock(\PHPCompiler\Block $block, string $name): ?\PHPCompiler\Block
    {
        if (($block->func->name ?? '') === $name) {
            return $block;
        }
        foreach ($block->opCodes as $op) {
            if (null !== $op->block1) {
                $found = $this->findFuncBlock($op->block1, $name);
                if (null !== $found) {
                    return $found;
                }
            }
            if (null !== $op->block2) {
                $found = $this->findFuncBlock($op->block2, $name);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function assertVmOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        try {
            $runtime->run($block);
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            // exit() in compiled code
        }
        $actual = ob_get_clean();
        $this->assertSame($expected, $actual, 'VM stdout');
    }
}
