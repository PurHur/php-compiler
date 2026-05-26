<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_change_key_case;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for array_change_key_case(). */
final class ArrayChangeKeyCaseBuiltinTest extends TestCase
{
    public function testLowerStringKeys(): void
    {
        $ht = new HashTable();
        foreach (['Foo' => 9, 'Bar' => 3] as $k => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->add($k, $val);
        }
        $out = $this->runChangeKeyCase($ht, 0);
        $this->assertSame(9, $out->find('foo')->resolveIndirect()->toInt());
        $this->assertSame(3, $out->find('bar')->resolveIndirect()->toInt());
    }

    public function testUpperStringKeys(): void
    {
        $ht = new HashTable();
        $val = new VMVariable();
        $val->int(1);
        $ht->add('foo', $val);
        $out = $this->runChangeKeyCase($ht, 1);
        $this->assertSame(1, $out->find('FOO')->resolveIndirect()->toInt());
    }

    public function testIntKeysUnchanged(): void
    {
        $ht = new HashTable();
        $val = new VMVariable();
        $val->string('x');
        $ht->addIndex(10, $val);
        $out = $this->runChangeKeyCase($ht, 0);
        $this->assertSame('x', $out->findIndex(10)->resolveIndirect()->toString());
    }

    private function runChangeKeyCase(HashTable $array, int $case): HashTable
    {
        $runtime = new Runtime();
        $fn = new array_change_key_case();
        $frame = $fn->getFrame($runtime->vmContext);
        $arrayVar = new VMVariable();
        $arrayVar->array($array);
        $caseVar = new VMVariable();
        $caseVar->int($case);
        $frame->calledArgs = [$arrayVar, $caseVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toArray();
    }
}
