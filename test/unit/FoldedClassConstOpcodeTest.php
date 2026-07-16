<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPCompiler\Runtime;

final class FoldedClassConstOpcodeTest extends TestCase
{
    public function testFoldedIntClassConstEchoUsesConstantSlot(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class K { public const I = 42; }
echo K::I;
PHP;
        $block = $runtime->parseAndCompile($code, 'folded_int_class_const.php');
        $this->assertNotNull($block);
        $echoUsesConstSlot = false;
        foreach ($block->opCodes as $op) {
            if (\PHPCompiler\OpCode::TYPE_ECHO !== $op->type) {
                continue;
            }
            if (isset($block->constants[$op->arg1])) {
                $echoUsesConstSlot = true;
                $this->assertSame(42, $block->constants[$op->arg1]->toInt());
            }
        }
        $this->assertTrue($echoUsesConstSlot);
    }

    public function testFoldedClassConstFetchLeavesConstantInBlockTable(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class K { public const S = 'abc'; }
echo K::S;
PHP;
        $block = $runtime->parseAndCompile($code, 'folded_class_const.php');
        $this->assertNotNull($block);
        $hasClassConstFetch = false;
        $hasConstFetch = false;
        $echoSlots = [];
        foreach ($block->opCodes as $op) {
            if (\PHPCompiler\OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                $hasClassConstFetch = true;
            }
            if (\PHPCompiler\OpCode::TYPE_CONST_FETCH === $op->type) {
                $hasConstFetch = true;
            }
            if (\PHPCompiler\OpCode::TYPE_ECHO === $op->type) {
                $echoSlots[] = $op->arg1;
            }
        }
        $this->assertFalse($hasClassConstFetch, 'folded fetch should not emit CLASS_CONST_FETCH');
        $stringConstSlots = [];
        foreach ($block->constants as $slot => $var) {
            if ($var->is(\PHPCompiler\VM\Variable::TYPE_STRING)) {
                $stringConstSlots[$slot] = $var->toString();
            }
        }
        $this->assertNotEmpty($stringConstSlots, 'abc should live in block constants');
        $this->assertContains('abc', array_values($stringConstSlots));
        $echoUsesConstSlot = false;
        foreach ($echoSlots as $slot) {
            if (isset($block->constants[$slot])) {
                $echoUsesConstSlot = true;
                $this->assertSame('abc', $block->constants[$slot]->toString());
            }
        }
        $this->assertTrue($echoUsesConstSlot, 'echo must reference folded constant slot');
    }

    public function testStaticClassConstFetchIsNotFolded(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    const X = 1;
    static function f() { return static::X; }
}
class B extends A {
    const X = 2;
}
echo B::f();
PHP;
        $block = $runtime->parseAndCompile($code, 'static_class_const_lsb.php');
        $this->assertNotNull($block);
        $foundRuntimeFetch = false;
        $seen = new \SplObjectStorage();
        $walk = function (\PHPCompiler\Block $b) use (&$walk, &$foundRuntimeFetch, $seen): void {
            if ($seen->contains($b)) {
                return;
            }
            $seen[$b] = true;
            foreach ($b->opCodes as $op) {
                if (\PHPCompiler\OpCode::TYPE_CLASS_CONST_FETCH === $op->type) {
                    $classOperand = $b->constants[$op->arg2] ?? null;
                    if (null === $classOperand) {
                        $operand = $b->getOperand($op->arg2);
                        if ($operand instanceof \PHPCfg\Operand\Literal) {
                            $this->assertSame('static', strtolower((string) $operand->value));
                            $foundRuntimeFetch = true;
                        }
                    } elseif ($classOperand->is(\PHPCompiler\VM\Variable::TYPE_STRING)) {
                        $this->assertSame('static', strtolower($classOperand->toString()));
                        $foundRuntimeFetch = true;
                    }
                }
                if (null !== $op->block1) {
                    $walk($op->block1);
                }
                if (null !== $op->block2) {
                    $walk($op->block2);
                }
            }
        };
        $walk($block);
        $this->assertTrue(
            $foundRuntimeFetch,
            'static::X must emit CLASS_CONST_FETCH (not fold to declaring-class value) (#19614)'
        );
    }
}
