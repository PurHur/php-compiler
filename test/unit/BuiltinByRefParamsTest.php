<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** By-reference builtin parameter metadata (issue #3161, #3583). */
final class BuiltinByRefParamsTest extends TestCase
{
    public function testSimilarTextThirdArgument(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('similar_text'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('SIMILAR_TEXT'));
    }

    public function testSortFirstArgument(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('sort'));
        $this->assertSame([0], BuiltinByRefParams::forFunction('SORT'));
    }

    public function testArrayWalkFirstArgument(): void
    {
        $this->assertSame([0], BuiltinByRefParams::forFunction('array_walk'));
    }

    public function testArrayMultisortVariadicByRef(): void
    {
        $this->assertSame(0, BuiltinByRefParams::variadicByRefFromIndex('array_multisort'));
    }

    public function testOpensslRandomPseudoBytesSecondArgument(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_random_pseudo_bytes'));
    }
}
