<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::register always-on SessionStartOptions NestedJIT (#33945 / peer #33909).
 *
 * NestedJIT/AOT bridge stays SessionStartOptionsRuntime / JitSessionStartOptions
 * (php-src ext/session/session.c). Call-site ensureLinked owns the ABI so leftover
 * Type always-on NestedJIT cannot mint session_start_options_apply.1
 * (#31894 / #32122).
 */
final class TypeDeadSessionStartOptionsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeRegisterDropsEagerSessionStartOptionsEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33945', $type);
        $this->assertStringNotContainsString(
            'SessionStartOptionsRuntime::ensureLinked($this->context)',
            $type,
            'Builtin\\Type::register must not eagerly ensureLinked SessionStartOptions (#33945)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_session_start_options_apply[\'"]/',
            $type,
            'Builtin\\Type must not always-declare session_start_options ABI (#33945)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__phpc_session_start_options_apply'",
            $type,
            'Builtin\\Type must not always-register session_start_options ABI (#33945)'
        );
    }

    public function testRuntimeOwnerLinksTriggerErrorBeforeNestedJit(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionStartOptionsRuntime.php');
        $this->assertStringContainsString('#33945', $owner);
        $this->assertStringContainsString('#33248', $owner);
        $this->assertStringContainsString('StringTriggerError::ensureLinked($context)', $owner);
        $posTrig = strpos($owner, 'StringTriggerError::ensureLinked($context)');
        $posCompiled = strpos($owner, 'JitVmHelperLink::ensureCompiled');
        $this->assertNotFalse($posTrig);
        $this->assertNotFalse($posCompiled);
        $this->assertLessThan(
            $posCompiled,
            $posTrig,
            'SessionStartOptionsRuntime::ensureLinked must link trigger_error before NestedJIT (#33248/#33945)'
        );
        $this->assertStringContainsString('__phpc_session_start_options_apply', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
    }

    public function testCallSiteEnsureLinksBeforeLookup(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionStartOptions.php');
        $this->assertStringContainsString('SessionStartOptionsRuntime::ensureLinked($context)', $jit);
        $this->assertStringContainsString('SessionStartOptionsRuntime::ABI', $jit);
    }

    public function testNoNewRuntimeCForSessionStartOptionsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/session_start_options.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/session_start_options.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_session_start_options.c');
    }
}
