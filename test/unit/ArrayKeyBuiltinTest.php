<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_key_first;
use PHPCompiler\ext\standard\array_key_last;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for array_key_first() / array_key_last(). */
final class ArrayKeyBuiltinTest extends TestCase
{
    public function testFirstAndLastPackedAndAssoc(): void
    {
        $runtime = new Runtime();
        $firstFn = new array_key_first();
        $lastFn = new array_key_last();

        $empty = new HashTable();
        $this->assertNull($this->runKey($firstFn, $runtime, $empty));
        $this->assertNull($this->runKey($lastFn, $runtime, $empty));

        $list = new HashTable();
        foreach ([10, 20, 30] as $i => $val) {
            $cell = new VMVariable();
            $cell->int($val);
            $list->addIndex($i, $cell);
        }
        $this->assertSame(0, $this->runKey($firstFn, $runtime, $list)->toInt());
        $this->assertSame(2, $this->runKey($lastFn, $runtime, $list)->toInt());

        $assoc = new HashTable();
        $x = new VMVariable();
        $x->int(1);
        $assoc->add('x', $x);
        $y = new VMVariable();
        $y->int(2);
        $assoc->add('y', $y);
        $this->assertSame('x', $this->runKey($firstFn, $runtime, $assoc)->toString());
        $this->assertSame('y', $this->runKey($lastFn, $runtime, $assoc)->toString());
    }

    private function runKey(Internal $fn, Runtime $runtime, HashTable $ht): ?VMVariable
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->array($ht);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $out = $frame->returnVar->resolveIndirect();
        if (VMVariable::TYPE_NULL === $out->type) {
            return null;
        }

        return $out;
    }
}
