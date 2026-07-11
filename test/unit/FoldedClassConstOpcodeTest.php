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
}
