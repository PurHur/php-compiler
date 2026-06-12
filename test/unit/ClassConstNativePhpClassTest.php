<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPUnit\Framework\TestCase;

/**
 * Native PHP class constants in class const initializers (bootstrap spine; #6221).
 */
final class ClassConstNativePhpClassTest extends TestCase
{
    public function testMaterializeSlotResolvesJitVariableTypeConstants(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
namespace PHPCompiler\JIT;

final class VariableTypeMapNativeProbe
{
    private const VM_TO_JIT = [
        Variable::TYPE_NULL => Variable::TYPE_NULL,
    ];
}
PHP;
        $block = $runtime->parseAndCompile($code, 'VariableTypeMapNativeProbe.php');
        $this->assertNotNull($block);

        $classBlock = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS === $op->type) {
                $classBlock = $op->block1;
                break;
            }
        }
        $this->assertNotNull($classBlock);

        $slot = null;
        foreach ($classBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST !== $op->type) {
                continue;
            }
            $constName = $this->literalOperandString($classBlock->getOperand($op->arg1));
            if ('VM_TO_JIT' === $constName) {
                $slot = $op->arg2;
                break;
            }
        }
        $this->assertNotNull($slot);

        $vm = new VM($runtime->vmContext);
        $value = ClassConstMaterializer::materializeSlot(
            $vm,
            $classBlock,
            $slot,
            'PHPCompiler\\JIT\\VariableTypeMapNativeProbe'
        );
        $this->assertSame(VM\Variable::TYPE_ARRAY, $value->type);
        $table = $value->toArray();
        $key = new VM\Variable(VM\Variable::TYPE_INTEGER);
        $key->int(JitVariable::TYPE_NULL);
        $this->assertTrue($table->keyExists($key));
        $elem = $table->findVariable($key, false);
        $this->assertNotNull($elem);
        $this->assertSame(JitVariable::TYPE_NULL, $elem->resolveIndirect()->toInt());
    }

    public function testMaterializeSlotResolvesNativePhpArrayClassConstants(): void
    {
        require_once __DIR__.'/../../ext/standard/VmAssertState.php';
        require_once __DIR__.'/../../ext/standard/VmIni.php';

        $runtime = new Runtime();
        $code = (string) file_get_contents(__DIR__.'/../../ext/standard/VmIni.php');
        $block = $runtime->parseAndCompile($code, 'VmIni.php');
        $this->assertNotNull($block);

        $classBlock = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS === $op->type) {
                $classBlock = $op->block1;
                break;
            }
        }
        $this->assertNotNull($classBlock);

        $slot = null;
        foreach ($classBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST !== $op->type) {
                continue;
            }
            $constName = $this->literalOperandString($classBlock->getOperand($op->arg1));
            if ('SUPPORTED_KEYS' === $constName) {
                $slot = $op->arg2;
                break;
            }
        }
        $this->assertNotNull($slot);

        $vm = new VM($runtime->vmContext);
        $value = ClassConstMaterializer::materializeSlot(
            $vm,
            $classBlock,
            $slot,
            'PHPCompiler\\ext\\standard\\VmIni'
        );
        $this->assertSame(VM\Variable::TYPE_ARRAY, $value->type);
        $this->assertGreaterThanOrEqual(9, $value->toArray()->getNumElements());
    }

    private function literalOperandString(object $op): string
    {
        if (property_exists($op, 'value')) {
            return (string) $op->value;
        }

        return '';
    }
}
