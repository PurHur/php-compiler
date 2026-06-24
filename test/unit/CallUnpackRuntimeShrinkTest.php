<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\CallUnpackJitHelper;
use PHPCompiler\VM\CallUnpackSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** CallUnpackHelper routes compile-time unpack through VM PHP SSOT (#10202). */
final class CallUnpackRuntimeShrinkTest extends TestCase
{
    public function testCallUnpackHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/CallUnpackHelper.php');
        $this->assertStringContainsString('CallUnpackCompileTime::', $helper);
        $this->assertStringNotContainsString('private static function tryCompileTimeArrayFromSlot', $helper);
        $this->assertLessThan(55, substr_count($helper, "\n"));
    }

    public function testCallUnpackCompileTimeUsesCallUnpackSupport(): void
    {
        $compileTime = (string) file_get_contents(__DIR__.'/../../lib/JIT/CallUnpackCompileTime.php');
        $this->assertStringContainsString('CallUnpackSupport::expandArrayEntries', $compileTime);
        $this->assertStringContainsString('CallUnpackJitHelper::vmArrayFromElements', $compileTime);
    }

    public function testCallUnpackSupportDelegatesToCallUnpack(): void
    {
        $spread = new Variable(Variable::TYPE_ARRAY);
        $ht = $spread->newArray();
        $key = new Variable(Variable::TYPE_STRING);
        $key->string('a');
        $val = new Variable(Variable::TYPE_INTEGER);
        $val->int(1);
        $ht->add('a', $val);

        $entries = CallUnpackSupport::expandArrayEntries($spread, ['a', 'b'], null);
        $this->assertSame([['n', 'a', 1]], array_map(
            static fn (array $e): array => [$e[0], $e[1], $e[2]->toInt()],
            $entries
        ));
    }

    public function testCallUnpackJitHelperBuildsKeyedArray(): void
    {
        $key = new Variable(Variable::TYPE_STRING);
        $key->string('x');
        $val = new Variable(Variable::TYPE_INTEGER);
        $val->int(9);
        $array = CallUnpackJitHelper::vmArrayFromElements([[$key, $val]]);
        $this->assertSame(9, $array->toArray()->find('x')->toInt());
    }
}
