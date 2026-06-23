<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\ArrayFilterCallbackPolicy;
use PHPUnit\Framework\TestCase;

final class ArrayFilterCallbackPolicyTest extends TestCase
{
    public function testInvalidCallbackTypeError(): void
    {
        self::assertSame(
            'array_filter(): Argument #2 ($callback) must be a valid callback or null, no array or string given',
            ArrayFilterCallbackPolicy::invalidCallbackTypeError()
        );
    }

    public function testInvalidArrayCallbackTypeError(): void
    {
        self::assertSame(
            'array_filter(): Argument #2 ($callback) must be a valid callback or null, array callback must have exactly two members',
            ArrayFilterCallbackPolicy::invalidArrayCallbackTypeError()
        );
    }

    public function testInvalidStringCallbackTypeError(): void
    {
        self::assertSame(
            'array_filter(): Argument #2 ($callback) must be a valid callback or null, function "foo" not found or invalid function name',
            ArrayFilterCallbackPolicy::invalidStringCallbackTypeError('foo')
        );
    }
}
