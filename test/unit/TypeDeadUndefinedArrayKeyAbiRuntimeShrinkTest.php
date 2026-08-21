<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on undefined_array_key warning ABI shells from Builtin\Type (#33249).
 *
 * NestedJIT/AOT bridge stays StringTriggerError / JitTriggerErrorKernel
 * (php-src Zend/zend_execute.c). Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint
 * undefined_array_key_warning_*.1 (#31894 / #32122).
 */
final class TypeDeadUndefinedArrayKeyAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnUndefinedArrayKeyAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33249', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_undefined_array_key_warning_cstr[\'"]/',
            $type,
            'Builtin\\Type must not always-declare undef-key cstr ABI (#33249)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_undefined_array_key_warning_long[\'"]/',
            $type,
            'Builtin\\Type must not always-declare undef-key long ABI (#33249)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_undefined_array_key_warning_cstr'",
            $type,
            'Builtin\\Type must not always-register undef-key cstr ABI (#33249)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_undefined_array_key_warning_long'",
            $type,
            'Builtin\\Type must not always-register undef-key long ABI (#33249)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (pending header still Type always-on; #33249 undef-key dropped).
        $this->assertStringContainsString("registerFunction('__phpc_pending_header_reset'", $type);
        $this->assertStringContainsString('StringTriggerError::ensureLinked', $type);
        $this->assertStringContainsString('StringTriggerError::declareUndefinedArrayKeyAbis', $type);
    }

    public function testRuntimeOwnerDeclaresUndefinedArrayKeyAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTriggerError.php');
        $this->assertStringContainsString('#33249', $owner);
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTriggerErrorJit.php');
        $this->assertStringContainsString('#33249', $jit);
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTriggerErrorKernel.php');
        $this->assertStringContainsString('#33249', $kernel);
        $this->assertStringContainsString('getNamedFunction', $kernel);
        $this->assertStringContainsString('declareUndefinedArrayKeyAbis', $kernel);
        $this->assertStringContainsString('implementUndefKeyCstrBridge', $kernel);
        $this->assertStringContainsString('implementUndefKeyLongBridge', $kernel);
        $this->assertStringContainsString('__compiler_undefined_array_key_warning_cstr', $kernel);
        $this->assertStringContainsString('__compiler_undefined_array_key_warning_long', $kernel);
    }

    public function testTypeInitializeStillEnsureLinksStringTriggerError(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringTriggerError::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForUndefinedArrayKeyAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/undefined_array_key.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/undefined_array_key.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/compiler_undefined_array_key.c');
    }
}
