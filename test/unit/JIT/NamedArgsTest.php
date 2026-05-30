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
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown named parameter $foo');
        NamedArgs::resolveOutgoing(
            [['named' => 'foo', 'value' => $v]],
            [null],
            ['a'],
            null
        );
    }
}
