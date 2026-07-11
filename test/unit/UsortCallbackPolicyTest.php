<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Call\Native;
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

    public function testJitAllowsClosureCallbacks(): void
    {
        $callback = (new \ReflectionClass(JITVariable::class))->newInstanceWithoutConstructor();
        $callback->closureCall = $this->createMock(Native::class);

        $this->assertTrue(UsortCallbackPolicy::isClosureJitLowerable($callback));
        $this->assertTrue(UsortCallbackPolicy::isJitLowerable($callback));
    }

    public function testVmAllowsStrcmpFamilyNames(): void
    {
        foreach (['strcmp', 'strcasecmp', 'strcoll', 'strnatcmp', 'strnatcasecmp'] as $name) {
            $this->assertTrue(UsortCallbackPolicy::isVmSupportedName($name), $name);
        }
        $this->assertFalse(UsortCallbackPolicy::isVmSupportedName('strlen'));
    }
}
