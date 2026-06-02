<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_first;
use PHPCompiler\ext\standard\array_last;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for array_first() / array_last() (#3491). */
final class ArrayFirstLastBuiltinTest extends TestCase
{
    public function testFirstAndLastPackedAndAssoc(): void
    {
        $runtime = new Runtime();
        $firstFn = new array_first();
        $lastFn = new array_last();

        $assoc = new HashTable();
        $x = new VMVariable();
        $x->int(1);
        $assoc->add('x', $x);
        $y = new VMVariable();
        $y->int(2);
        $assoc->add('y', $y);
        $this->assertSame(1, $this->runElem($firstFn, $runtime, $assoc)->toInt());
        $this->assertSame(2, $this->runElem($lastFn, $runtime, $assoc)->toInt());

        $list = new HashTable();
        foreach ([10, 20, 30] as $i => $val) {
            $cell = new VMVariable();
            $cell->int($val);
            $list->addIndex($i, $cell);
        }
        $this->assertSame(10, $this->runElem($firstFn, $runtime, $list)->toInt());
        $this->assertSame(30, $this->runElem($lastFn, $runtime, $list)->toInt());
    }

    public function testEmptyArrayThrowsValueError(): void
    {
        $runtime = new Runtime();
        $firstFn = new array_first();
        $empty = new HashTable();

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('array_first(): Argument #1 ($array) must not be empty');
        $this->runElem($firstFn, $runtime, $empty);
    }

    public function testNonArrayThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $lastFn = new array_last();
        $frame = $lastFn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('bad');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('array_last(): Argument #1 ($array) must be of type array');
        $lastFn->execute($frame);
    }

    private function runElem(Internal $fn, Runtime $runtime, HashTable $ht): VMVariable
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->array($ht);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->resolveIndirect();
    }
}
