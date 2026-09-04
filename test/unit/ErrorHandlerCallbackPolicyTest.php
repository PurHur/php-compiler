<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ErrorHandlerCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerCallbackPolicyTest extends TestCase
{
    public function testJitAllowsCompileTimeUserFunctionName(): void
    {
        $this->assertTrue(ErrorHandlerCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            'my_handler'
        ));
        $this->assertFalse(ErrorHandlerCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            null
        ));
    }

    public function testJitRejectionMentionsClosuresAreSupported(): void
    {
        $msg = ErrorHandlerCallbackPolicy::jitRejectionMessage();
        $this->assertStringContainsString('closure', $msg);
        $this->assertStringNotContainsString('closures, array callables, and invokable objects are deferred', $msg);
    }

    public function testVmAllowsNullAndStringCallbackTypes(): void
    {
        $this->assertTrue(ErrorHandlerCallbackPolicy::isVmSupportedType(\PHPCompiler\VM\Variable::TYPE_NULL));
        $this->assertTrue(ErrorHandlerCallbackPolicy::isVmSupportedType(\PHPCompiler\VM\Variable::TYPE_STRING));
        $this->assertFalse(ErrorHandlerCallbackPolicy::isVmSupportedType(\PHPCompiler\VM\Variable::TYPE_OBJECT));
    }

    public function testInvalidCallbackTypeErrorMatchesZendSubset(): void
    {
        $this->assertSame(
            'set_error_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given',
            ErrorHandlerCallbackPolicy::invalidCallbackTypeError()
        );
    }
}
