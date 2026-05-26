<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_fill_keys;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for array_fill_keys(). */
final class ArrayFillKeysBuiltinTest extends TestCase
{
    public function testFillKeysStringAndInt(): void
    {
        $runtime = new Runtime();
        $fn = new array_fill_keys();

        $keys = new HashTable();
        $a = new VMVariable();
        $a->string('foo');
        $keys->addIndex(0, $a);
        $b = new VMVariable();
        $b->string('bar');
        $keys->addIndex(1, $b);
        $fill = new VMVariable();
        $fill->string('baz');
        $out = $this->runFill($fn, $runtime, $keys, $fill);
        $assoc = [];
        foreach ($out->iterateKeyed(true) as [$key, $val]) {
            $assoc[VMVariable::TYPE_STRING === $key->type ? $key->toString() : $key->toInt()] = $val->toString();
        }
        $this->assertSame(['foo' => 'baz', 'bar' => 'baz'], $assoc);

        $keys2 = new HashTable();
        $k0 = new VMVariable();
        $k0->int(0);
        $keys2->addIndex(0, $k0);
        $k1 = new VMVariable();
        $k1->int(1);
        $keys2->addIndex(1, $k1);
        $fill2 = new VMVariable();
        $fill2->string('x');
        $out2 = $this->runFill($fn, $runtime, $keys2, $fill2);
        $list = [];
        foreach ($out2->iterateKeyed(true) as [$key, $val]) {
            $list[$key->toInt()] = $val->toString();
        }
        $this->assertSame([0 => 'x', 1 => 'x'], $list);
    }

    private function runFill(Internal $fn, Runtime $runtime, HashTable $keys, VMVariable $value): HashTable
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $keysVar = new VMVariable();
        $keysVar->array($keys);
        $frame->calledArgs = [$keysVar, $value];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->resolveIndirect()->toArray();
    }
}
