<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPUnit\Framework\TestCase;

final class ArrayReduceCallbackPolicyTest extends TestCase
{
    public function testJitAllowsCompileTimeUserFunctionName(): void
    {
        $this->assertTrue(ArrayReduceCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            'sum'
        ));
        $this->assertFalse(ArrayReduceCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            null
        ));
    }

    public function testJitAllowsClosureCallback(): void
    {
        $callback = (new \ReflectionClass(JITVariable::class))->newInstanceWithoutConstructor();
        $callback->closureCall = $this->createMock(Native::class);
        $this->assertTrue(ArrayReduceCallbackPolicy::isClosureJitLowerable($callback));
        $this->assertTrue(ArrayReduceCallbackPolicy::isJitLowerable($callback));
    }

    public function testVmAllowsStringCallbackType(): void
    {
        $this->assertTrue(ArrayReduceCallbackPolicy::isVmSupportedType(\PHPCompiler\VM\Variable::TYPE_STRING));
        $this->assertFalse(ArrayReduceCallbackPolicy::isVmSupportedType(\PHPCompiler\VM\Variable::TYPE_NULL));
    }

    public function testRejectionMessagesMentionDeferredKinds(): void
    {
        $this->assertStringContainsString('array callables', ArrayReduceCallbackPolicy::jitRejectionMessage());
        $this->assertStringContainsString('array callables', ArrayReduceCallbackPolicy::vmRejectionMessage());
    }

    public function testInvalidCallbackTypeErrorMatchesZendSubset(): void
    {
        $this->assertSame(
            'array_reduce(): Argument #2 ($callback) must be a valid callback, no array or string given',
            ArrayReduceCallbackPolicy::invalidCallbackTypeError()
        );
        $this->assertSame(
            'array_reduce(): Argument #2 ($callback) must be a valid callback, function "missing_fn" not found or invalid function name',
            ArrayReduceCallbackPolicy::invalidStringCallbackTypeError('missing_fn')
        );
    }
}
