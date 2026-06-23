<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ksort_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for ksort(). */
final class KsortBuiltinTest extends TestCase
{
    public function testSortsStringKeys(): void
    {
        $runtime = new Runtime();
        $fn = new ksort_();
        $ht = new HashTable();
        foreach (['b' => 2, 'a' => 1, 'c' => 3] as $k => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->add($k, $val);
        }
        $sorted = $this->runKsort($fn, $runtime, $ht);
        $keys = [];
        foreach ($sorted->iterateKeyed(true) as [$key]) {
            $keys[] = $key->toString();
        }
        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    public function testSortsIntegerKeys(): void
    {
        $runtime = new Runtime();
        $fn = new ksort_();
        $ht = new HashTable();
        foreach ([30 => 'c', 10 => 'a', 20 => 'b'] as $k => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($k, $val);
        }
        $sorted = $this->runKsort($fn, $runtime, $ht);
        $keys = [];
        foreach ($sorted->iterateKeyed(true) as [$key]) {
            $keys[] = $key->toInt();
        }
        $this->assertSame([10, 20, 30], $keys);
    }

    public function testPackedListSortsByKeyAscending(): void
    {
        $runtime = new Runtime();
        $fn = new ksort_();
        $ht = new HashTable();
        foreach ([1, 2, 3] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runKsort($fn, $runtime, $ht);
        $keys = [];
        foreach ($sorted->iterateKeyed(true) as [$key]) {
            $keys[] = $key->toInt();
        }
        $this->assertSame([0, 1, 2], $keys);
    }

    public function testTypeErrorOnObject(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php class Dummy {}', 'dummy.php');
        self::assertNotNull($block);
        $runtime->run($block);
        $class = $runtime->vmContext->classes['dummy'];
        $entry = new ObjectEntry($class);
        $entry->constructed = true;

        $fn = new ksort_();
        $frame = $fn->getFrame($runtime->vmContext);
        $obj = new VMVariable();
        $obj->object($entry);
        $frame->calledArgs = [$obj];
        $frame->returnVar = new VMVariable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ksort(): Argument #1 ($array) must be of type array, Dummy given');
        $fn->execute($frame);
    }

    private function runKsort(Internal $fn, Runtime $runtime, HashTable $array): HashTable
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $arrayVar = new VMVariable();
        $arrayVar->array($array);
        $frame->calledArgs = [$arrayVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $arrayVar->resolveIndirect()->toArray();
    }
}
