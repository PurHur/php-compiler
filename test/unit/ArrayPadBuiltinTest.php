<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_pad;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for array_pad(). */
final class ArrayPadBuiltinTest extends TestCase
{
    public function testPadRightAndLeft(): void
    {
        $runtime = new Runtime();
        $fn = new array_pad();

        $src = new HashTable();
        $one = new VMVariable();
        $one->int(1);
        $src->addIndex(0, $one);
        $two = new VMVariable();
        $two->int(2);
        $src->addIndex(1, $two);
        $three = new VMVariable();
        $three->int(3);
        $src->addIndex(2, $three);
        $pad = new VMVariable();
        $pad->string('x');

        $right = $this->runPad($fn, $runtime, $src, 6, $pad);
        $this->assertSame(6, $right->getNumElements());
        $vals = [];
        foreach ($right->iterate(true) as $i => $v) {
            $vals[$i] = VMVariable::TYPE_STRING === $v->type ? $v->toString() : $v->toInt();
        }
        $this->assertSame([1, 2, 3, 'x', 'x', 'x'], $vals);

        $src2 = new HashTable();
        $a = new VMVariable();
        $a->int(1);
        $src2->addIndex(0, $a);
        $b = new VMVariable();
        $b->int(2);
        $src2->addIndex(1, $b);
        $zero = new VMVariable();
        $zero->int(0);
        $left = $this->runPad($fn, $runtime, $src2, -4, $zero);
        $this->assertSame(4, $left->getNumElements());
        $leftVals = [];
        foreach ($left->iterate(true) as $v) {
            $leftVals[] = $v->toInt();
        }
        $this->assertSame([0, 0, 1, 2], $leftVals);
    }

    private function runPad(
        Internal $fn,
        Runtime $runtime,
        HashTable $array,
        int $length,
        VMVariable $value
    ): HashTable {
        $frame = $fn->getFrame($runtime->vmContext);
        $arrayVar = new VMVariable();
        $arrayVar->array($array);
        $lenVar = new VMVariable();
        $lenVar->int($length);
        $frame->calledArgs = [$arrayVar, $lenVar, $value];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->resolveIndirect()->toArray();
    }
}
