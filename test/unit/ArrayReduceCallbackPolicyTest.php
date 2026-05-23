<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

final class ArrayReduceCallbackPolicyTest extends TestCase
{
    public function testJitLowerableWithCompileTimeString(): void
    {
        $this->assertTrue(ArrayReduceCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            'pow'
        ));
    }

    public function testJitNotLowerableWithoutCompileTimeString(): void
    {
        $this->assertFalse(ArrayReduceCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            null
        ));
    }

    public function testVmSupportedTypeIsStringOnly(): void
    {
        $this->assertTrue(ArrayReduceCallbackPolicy::isVmSupportedType(VMVariable::TYPE_STRING));
        $this->assertFalse(ArrayReduceCallbackPolicy::isVmSupportedType(VMVariable::TYPE_NULL));
    }

    public function testRejectionMessagesMentionClosures(): void
    {
        $this->assertStringContainsString('closures', ArrayReduceCallbackPolicy::jitRejectionMessage());
        $this->assertStringContainsString('closures', ArrayReduceCallbackPolicy::vmRejectionMessage());
    }
}
