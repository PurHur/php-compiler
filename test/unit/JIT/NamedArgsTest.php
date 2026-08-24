<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\JIT\NamedArgs;
use PHPUnit\Framework\TestCase;

final class NamedArgsTest extends TestCase
{
    public function testResolverReordersNamedArguments(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        [$resolved] = NamedArgs::resolveOutgoing(
            [
                ['named' => 'b', 'value' => $b],
                ['named' => 'a', 'value' => $a],
            ],
            [null, null],
            ['a', 'b'],
            null
        );
        $this->assertSame($a, $resolved[0]);
        $this->assertSame($b, $resolved[1]);
    }

    public function testUnknownNamedParameterRejects(): void
    {
        $v = new \stdClass();
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown named parameter $foo');
        NamedArgs::resolveOutgoing(
            [['named' => 'foo', 'value' => $v]],
            [null],
            ['a'],
            null
        );
    }

    public function testResolverPreservesOperandIdentityOnReorder(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $opA = new \stdClass();
        $opB = new \stdClass();
        [$resolved, $ops] = NamedArgs::resolveOutgoing(
            [
                ['named' => 'b', 'value' => $b, 'operand' => $opB],
                ['named' => 'a', 'value' => $a, 'operand' => $opA],
            ],
            [null, null],
            ['a', 'b'],
            null
        );
        $this->assertSame($a, $resolved[0]);
        $this->assertSame($b, $resolved[1]);
        $this->assertSame($opA, $ops[0]);
        $this->assertSame($opB, $ops[1]);
    }
}
