<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Runtime materialization for `const X = new C()` (#9116). */
final class ClassConstNewRuntimeTest extends TestCase
{
    public function testClassConstNewMaterializesObject(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
var_dump(Holder::X->n);
PHP, 'class_const_new_runtime.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('int(1)', $out, $out);
    }

    public function testClassConstNewEmitsInitOpcodesBeforeDeclare(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
PHP, 'class_const_new_ops.php');
        $this->assertNotNull($block);
        $holderBody = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $name = $block->constants[$op->arg1]->toString();
            if ('Holder' === $name) {
                $holderBody = $op->block1;
                break;
            }
        }
        $this->assertNotNull($holderBody);
        $typeNames = [];
        foreach ($holderBody->opCodes as $op) {
            $typeNames[] = \PHPCompiler\opcode_type_name($op->type);
        }
        $this->assertContains('TYPE_NEW', $typeNames, implode(', ', $typeNames));
        $declareIdx = null;
        $newIdx = null;
        foreach ($holderBody->opCodes as $i => $op) {
            if (OpCode::TYPE_NEW === $op->type) {
                $newIdx = $i;
            }
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                $declareIdx = $i;
            }
        }
        $this->assertNotNull($newIdx, 'missing TYPE_NEW in Holder body');
        $this->assertNotNull($declareIdx, 'missing DECLARE_CLASS_CONST in Holder body');
        $this->assertLessThan($declareIdx, $newIdx, 'TYPE_NEW must precede DECLARE_CLASS_CONST');
    }
}
