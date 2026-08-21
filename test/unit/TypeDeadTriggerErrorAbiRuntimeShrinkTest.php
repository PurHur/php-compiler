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
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        $this->assertStringContainsString('StringTriggerError::ensureLinked', $type);
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

    public function testTypeInitializeStillEnsureLinksStringTriggerError(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringTriggerError::ensureLinked($this->context)', $type);
    }

    public function testTypeRegisterLinksTriggerErrorBeforeSessionStartOptionsNestedJit(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33248', $type);
        // Match executable calls only (comments earlier mention the same symbols).
        $this->assertMatchesRegularExpression(
            '/StringTriggerError::ensureLinked\(\$this->context\);\s*\n\s*SessionStartOptionsRuntime::ensureLinked\(\$this->context\);/',
            $type,
            'Type::register must ensureLinked StringTriggerError immediately before SessionStartOptionsRuntime NestedJIT (#33248)'
        );
    }

    public function testContextLinksTriggerErrorBeforeAssertFail(): void
    {
        $assert = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertFail.php');
        $this->assertStringContainsString('#33234', $assert);
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#33234', $ctx);
        $posTrig = strpos($ctx, 'StringTriggerError::ensureStandaloneBodies($this)');
        $posAssert = strpos($ctx, 'AssertFail::ensureStandaloneBodies($this)');
        $this->assertNotFalse($posTrig);
        $this->assertNotFalse($posAssert);
        $this->assertLessThan($posAssert, $posTrig, 'StringTriggerError must precede AssertFail (#33234)');
    }

    public function testNoNewRuntimeCForTriggerErrorAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/trigger_error.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/trigger_error.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_trigger_error.c');
    }
}
