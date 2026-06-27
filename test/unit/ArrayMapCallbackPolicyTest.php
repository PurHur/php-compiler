<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

final class ArrayMapCallbackPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT');
        parent::tearDown();
    }

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

    public function testClosureCallbackWithProxyIsJitLowerable(): void
    {
        $callback = (new \ReflectionClass(JITVariable::class))->newInstanceWithoutConstructor();
        $callback->closureCall = $this->createMock(Native::class);
        $this->assertTrue(ArrayMapCallbackPolicy::isClosureJitLowerable($callback));
        $this->assertTrue(ArrayMapCallbackPolicy::isJitLowerable($callback));
    }

    public function testVmSupportedTypes(): void
    {
        $this->assertTrue(ArrayMapCallbackPolicy::isVmSupportedType(VMVariable::TYPE_NULL));
        $this->assertTrue(ArrayMapCallbackPolicy::isVmSupportedType(VMVariable::TYPE_STRING));
        $this->assertFalse(ArrayMapCallbackPolicy::isVmSupportedType(VMVariable::TYPE_ARRAY));
    }

    public function testPhpSrcInvalidCallbackTypes(): void
    {
        $this->assertTrue(ArrayMapCallbackPolicy::isPhpSrcInvalidCallbackType(VMVariable::TYPE_INTEGER));
        $this->assertTrue(ArrayMapCallbackPolicy::isJitPhpSrcInvalidCallbackType(JITVariable::TYPE_NATIVE_LONG));
        $this->assertStringContainsString(
            'valid callback',
            ArrayMapCallbackPolicy::invalidCallbackTypeError()
        );
    }

    public function testRejectionMessagesMentionDeferredKinds(): void
    {
        $this->assertStringContainsString('closures', ArrayMapCallbackPolicy::jitRejectionMessage());
        $this->assertStringContainsString('array callables', ArrayMapCallbackPolicy::vmRejectionMessage());
    }

    public function testDeferredNoteDocumentsSubset(): void
    {
        $this->assertStringContainsString('closures', SelfHostBuiltinPolicy::ARRAY_MAP_CALLBACK_DEFERRED_NOTE);
        $this->assertStringContainsString('string builtin', SelfHostBuiltinPolicy::ARRAY_MAP_CALLBACK_DEFERRED_NOTE);
    }

    public function testArrayMapStaysOnRealLoweringForSelfHost(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertTrue(SelfHostBuiltinPolicy::isRequiredForBundle('array_map'));
        $this->assertFalse(SelfHostBuiltinPolicy::shouldExternalStub('array_map'));
    }
}
