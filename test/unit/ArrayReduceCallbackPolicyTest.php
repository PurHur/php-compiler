<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
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

    public function testVmAllowsStringCallbackType(): void
    {
        $this->assertTrue(ArrayReduceCallbackPolicy::isVmSupportedType(\PHPCompiler\VM\Variable::TYPE_STRING));
        $this->assertFalse(ArrayReduceCallbackPolicy::isVmSupportedType(\PHPCompiler\VM\Variable::TYPE_NULL));
    }

    public function testRejectionMessagesMentionDeferredKinds(): void
    {
        $this->assertStringContainsString('closures', ArrayReduceCallbackPolicy::jitRejectionMessage());
        $this->assertStringContainsString('closures', ArrayReduceCallbackPolicy::vmRejectionMessage());
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
