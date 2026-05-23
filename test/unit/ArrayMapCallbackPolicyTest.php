<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

final class ArrayMapCallbackPolicyTest extends TestCase
{
    public function testNullCallbackIsJitLowerable(): void
    {
        $this->assertTrue(ArrayMapCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_NULL,
            true,
            null
        ));
    }

    public function testCompileTimeStringCallbackIsJitLowerable(): void
    {
        $this->assertTrue(ArrayMapCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            'strval'
        ));
    }

    public function testRuntimeStringCallbackIsNotJitLowerable(): void
    {
        $this->assertFalse(ArrayMapCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            null
        ));
    }

    public function testVmSupportedTypes(): void
    {
        $this->assertTrue(ArrayMapCallbackPolicy::isVmSupportedType(VMVariable::TYPE_NULL));
        $this->assertTrue(ArrayMapCallbackPolicy::isVmSupportedType(VMVariable::TYPE_STRING));
        $this->assertFalse(ArrayMapCallbackPolicy::isVmSupportedType(VMVariable::TYPE_ARRAY));
    }

    public function testRejectionMessagesMentionDeferredKinds(): void
    {
        $this->assertStringContainsString('closures', ArrayMapCallbackPolicy::jitRejectionMessage());
        $this->assertStringContainsString('array callables', ArrayMapCallbackPolicy::vmRejectionMessage());
    }
}
