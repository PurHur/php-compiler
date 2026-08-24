<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_error_log ABI shell from Builtin\Type (#33044).
 *
 * NestedJIT/AOT bridge stays StringErrorLog + ErrorLogJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint error_log.1 (#31894 / #32122).
 */
final class TypeDeadErrorLogAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnErrorLogAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33044', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_error_log[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_error_log (#33044)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_error_log'",
            $type,
            'Builtin\\Type must not always-register __compiler_error_log (#33044)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('StringErrorLog::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresErrorLogAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringErrorLog.php');
        $this->assertStringContainsString('#33044', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_error_log', $owner);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ErrorLogJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitErrorLog.php');
    }

    public function testTypeInitializeDropsEagerErrorLogEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString(
            'StringErrorLog::ensureLinked($this->context)',
            $type,
            'Builtin\\Type::initialize must not eagerly StringErrorLog::ensureLinked($this->context) (#34423)'
        );
    }

    public function testNoNewRuntimeCForErrorLogAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/error_log.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/error_log.c');
    }
}
