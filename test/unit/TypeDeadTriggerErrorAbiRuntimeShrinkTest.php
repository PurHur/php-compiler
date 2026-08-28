<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_trigger_error ABI shell from Builtin\Type (#33234).
 *
 * NestedJIT/AOT bridge stays StringTriggerError / JitTriggerErrorKernel / JitBuiltinWarning.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint trigger_error.1 (#31894 / #32122).
 */
final class TypeDeadTriggerErrorAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnTriggerErrorAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33234', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_trigger_error[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_trigger_error (#33234)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_trigger_error'",
            $type,
            'Builtin\\Type must not always-register __compiler_trigger_error (#33234)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop;
        // StringTriggerError register ensure moved to readStringKeyValue (#35648).
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('ensureUndefinedArrayKeyAbis', $ht);
    }

    public function testRuntimeOwnerDeclaresTriggerErrorAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTriggerError.php');
        $this->assertStringContainsString('#33234', $owner);
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTriggerErrorJit.php');
        $this->assertStringContainsString('#33234', $jit);
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTriggerErrorKernel.php');
        $this->assertStringContainsString('#33234', $kernel);
        $this->assertStringContainsString('getNamedFunction', $kernel);
        $this->assertStringContainsString('implementTriggerErrorBridge', $kernel);
        $this->assertStringContainsString('__compiler_trigger_error', $kernel);
        $warn = (string) file_get_contents(__DIR__.'/../../ext/standard/JitBuiltinWarning.php');
        $this->assertStringContainsString('#33234', $warn);
        $this->assertStringContainsString('StringTriggerError::ensureLinked', $warn);
        $trig = (string) file_get_contents(__DIR__.'/../../ext/standard/trigger_error_.php');
        $this->assertStringContainsString('#33234', $trig);
    }

    public function testTypeInitializeNoLongerEagerLinksStringTriggerError(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $initBody = substr($type, $initPos);
        $this->assertDoesNotMatchRegularExpression(
            '/StringTriggerError::ensureLinked\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly StringTriggerError::ensureLinked (#34513)'
        );
        // register() also dropped (#35392); HashTable::implement + call sites cover #33248.
        $regPos = strpos($type, 'public function register(): void');
        $this->assertNotFalse($regPos);
        $regBody = substr($type, $regPos, $initPos - $regPos);
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $regBody,
            'Type::register must not eagerly StringTriggerError::ensureLinked (#35392)'
        );
        $this->assertStringContainsString('#35392', $type);
    }

    public function testTypeRegisterNoLongerEagerLinksSessionStartOptionsNestedJit(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33248', $type);
        $this->assertStringContainsString('#33945', $type);
        $this->assertStringNotContainsString(
            'SessionStartOptionsRuntime::ensureLinked($this->context)',
            $type,
            'Type::register must not eagerly NestedJIT SessionStartOptions (#33945)'
        );
        // Former register pair (#33248) is gone; StringTriggerError register ensure
        // also dropped (#35392) — HashTable::implement + call sites cover O=0.
        $this->assertDoesNotMatchRegularExpression(
            '/StringTriggerError::ensureLinked\(\$this->context\);\s*\n\s*SessionStartOptionsRuntime::ensureLinked\(\$this->context\);/',
            $type,
            'Type::register must not pair trigger_error ensureLinked with SessionStartOptions NestedJIT (#33945)'
        );
        $this->assertStringNotContainsString(
            'StringTriggerError::declareUndefinedArrayKeyAbis($this->context)',
            $type,
            'Type::register must not declare undef-key ABIs (#35392; HashTable::implement owns it)'
        );
        $regPos = strpos($type, 'public function register(): void');
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($regPos);
        $this->assertNotFalse($initPos);
        $regBody = substr($type, $regPos, $initPos - $regPos);
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $regBody,
            'Type::register must not ensureLinked StringTriggerError (#35392)'
        );
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString(
            'ensureUndefinedArrayKeyAbis',
            $ht,
            'HashTable readStringKeyValue ensures undef-key ABIs (#35648 / #33249)'
        );
        $fnPos = strpos($ht, 'private function implementReadStringKeyValue(');
        $this->assertNotFalse($fnPos);
        $chunk = substr($ht, $fnPos, 400);
        $this->assertStringContainsString(
            'ensureUndefinedArrayKeyAbis',
            $chunk,
            'readStringKeyValue must ensure undef-key ABIs (#35648 / #33248)'
        );
        $ensureMethodPos = strpos($ht, 'private function ensureUndefinedArrayKeyAbis');
        $this->assertNotFalse($ensureMethodPos);
        $ensureChunk = substr($ht, $ensureMethodPos, 400);
        $this->assertStringContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $ensureChunk,
            'ensureUndefinedArrayKeyAbis must ensureLinked StringTriggerError (#35648 / #33248)'
        );
    }

    public function testAssertFailEnsureLinkedCoversTriggerErrorOrdering(): void
    {
        $assert = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertFail.php');
        $this->assertStringContainsString('#33234', $assert);
        $this->assertStringContainsString('#35073', $assert);
        $this->assertStringContainsString(
            'StringTriggerError::ensureLinked($context)',
            $assert,
            'AssertFail::ensureLinked must ensure StringTriggerError first (#33234 / #35073)'
        );
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35073', $ctx);
        $fullPos = strpos($ctx, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($ctx, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($ctx, $fullPos, $fullEnd - $fullPos);
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureStandaloneBodies($this)',
            $fullBody,
            'ensureFull must not eagerly StringTriggerError (#35073)'
        );
        $this->assertStringNotContainsString(
            'AssertFail::ensureStandaloneBodies($this)',
            $fullBody,
            'ensureFull must not eagerly AssertFail (#35073)'
        );
    }

    public function testNoNewRuntimeCForTriggerErrorAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/trigger_error.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/trigger_error.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_trigger_error.c');
    }
}
