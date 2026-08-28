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
        // No further Type always-on leftover after #33267 exit/abort drop;
        // StringTriggerError register ensure moved to readStringKeyValue (#35648).
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('ensureUndefinedArrayKeyAbis', $ht);
        $this->assertStringContainsString('StringTriggerError::declareUndefinedArrayKeyAbis', $ht);
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

    public function testTypeRegisterNoLongerEagerLinksStringTriggerError(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $regPos = strpos($type, 'public function register(): void');
        $this->assertNotFalse($regPos);
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $regBody = substr($type, $regPos, $initPos - $regPos);
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $regBody,
            'Type::register must not eagerly StringTriggerError::ensureLinked (#35392)'
        );
        $this->assertStringContainsString('#35392', $type);
    }

    public function testNoNewRuntimeCForUndefinedArrayKeyAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/undefined_array_key.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/undefined_array_key.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/compiler_undefined_array_key.c');
    }
}
