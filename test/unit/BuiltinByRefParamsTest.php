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

    public function testArrayMultisortOnlyArraysByRef(): void
    {
        $array = new \PHPCompiler\VM\Variable();
        $array->newArray();
        $flag = new \PHPCompiler\VM\Variable();
        $flag->int(SORT_ASC);

        $this->assertTrue(BuiltinByRefParams::isByRefArg('array_multisort', 0, $array));
        $this->assertFalse(BuiltinByRefParams::isByRefArg('array_multisort', 1, $flag));
    }

    public function testOpensslRandomPseudoBytesSecondArgument(): void
    {
        $this->assertSame([1], BuiltinByRefParams::forFunction('openssl_random_pseudo_bytes'));
    }

    public function testIsCallableThirdArgument(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('is_callable'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('IS_CALLABLE'));
    }

    public function testPregMatchMatchesArgument(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('preg_match'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('PREG_MATCH_ALL'));
    }

    public function testArrayPointerFirstArgument(): void
    {
        foreach (['next', 'prev', 'reset', 'end', 'current', 'key'] as $fn) {
            $this->assertSame([0], BuiltinByRefParams::forFunction($fn), $fn);
        }
    }
}
