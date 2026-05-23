<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPUnit\Framework\TestCase;

final class UsortCallbackPolicyTest extends TestCase
{
    public function testJitAllowsCompileTimeStrcmpOnly(): void
    {
        $this->assertTrue(UsortCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            'strcmp'
        ));
        $this->assertFalse(UsortCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            'strcasecmp'
        ));
        $this->assertFalse(UsortCallbackPolicy::isJitLowerableScalar(
            JITVariable::TYPE_STRING,
            false,
            null
        ));
    }

    public function testVmAllowsStrcmpAndStrcasecmpNames(): void
    {
        $this->assertTrue(UsortCallbackPolicy::isVmSupportedName('strcmp'));
        $this->assertTrue(UsortCallbackPolicy::isVmSupportedName('strcasecmp'));
        $this->assertFalse(UsortCallbackPolicy::isVmSupportedName('strlen'));
    }
}
