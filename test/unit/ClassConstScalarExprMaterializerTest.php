<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClassConstMaterializer;
use PHPUnit\Framework\TestCase;

/**
 * Class constant scalar expression materialization at define time (#5394, #3567).
 */
final class ClassConstScalarExprMaterializerTest extends TestCase
{
    public function testMaterializeSlotEvaluatesArithmeticAndSelfConstFetch(): void
    {
        $runtime = new Runtime();
        $path = dirname(__DIR__).'/compliance/cases/language/class_const_scalar_expr_run.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, $path);
        $this->assertNotNull($block);

        $classBlock = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS === $op->type) {
                $classBlock = $op->block1;
                break;
            }
        }
        $this->assertNotNull($classBlock);

        $bSlot = null;
        foreach ($classBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST !== $op->type) {
                continue;
            }
            $constName = $this->literalOperandString($classBlock->getOperand($op->arg1));
            if ('B' === $constName) {
                $bSlot = $op->arg2;
                break;
            }
        }
        $this->assertNotNull($bSlot);

        $vm = new VM($runtime->vmContext);
        $value = ClassConstMaterializer::materializeSlot($vm, $classBlock, $bSlot, 'C');
        $this->assertSame(15, $value->toInt());

        $xSlot = null;
        foreach ($classBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST !== $op->type) {
                continue;
            }
            $constName = $this->literalOperandString($classBlock->getOperand($op->arg1));
            if ('X' === $constName) {
                $xSlot = $op->arg2;
                break;
            }
        }
        $this->assertNotNull($xSlot);
        $xVal = ClassConstMaterializer::materializeSlot($vm, $classBlock, $xSlot, 'C');
        $this->assertSame(3, $xVal->toInt());
    }

    private function literalOperandString(object $op): string
    {
        if (property_exists($op, 'value')) {
            return (string) $op->value;
        }

        return '';
    }
}
