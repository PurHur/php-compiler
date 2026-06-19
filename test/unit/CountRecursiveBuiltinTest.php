<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** count() COUNT_RECURSIVE VM helper (issue #3511). */
final class CountRecursiveBuiltinTest extends TestCase
{
    public function testCountRecursiveNestedList(): void
    {
        $inner = new HashTable();
        $inner->addIndex(0, $this->intVar(2));
        $inner->addIndex(1, $this->intVar(3));
        $outer = new HashTable();
        $outer->addIndex(0, $this->intVar(1));
        $arr = new Variable(Variable::TYPE_ARRAY);
        $arr->array($inner);
        $outer->addIndex(1, $arr);

        $this->assertSame(4, VmArray::countRecursive($outer));
    }

    public function testCountRecursiveSelfReferenceReturnsZeroBranch(): void
    {
        $a = new HashTable();
        $ref = new Variable(Variable::TYPE_ARRAY);
        $ref->array($a);
        $a->addIndex(0, $ref);

        $this->assertSame(1, VmArray::countRecursive($a));
    }

    public function testCountModeConstantsMatchZend(): void
    {
        $this->assertSame(0, VmArray::COUNT_NORMAL);
        $this->assertSame(1, VmArray::COUNT_RECURSIVE);
    }

    private function intVar(int $n): Variable
    {
        $v = new Variable(Variable::TYPE_INTEGER);
        $v->int($n);

        return $v;
    }
}
