<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPUnit\Framework\TestCase;

final class ArrayReduceCallbackPolicyTest extends TestCase
{
    public function testJitRejectsAllCallbacks(): void
    {
        $this->assertFalse(ArrayReduceCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            'sum'
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
}
