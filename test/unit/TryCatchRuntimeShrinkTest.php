<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Catch-arm type matching: AOT uses LLVM instanceof (#34597); VM keeps VmTryCatch (#9663).
 * TryCatchRuntime NestedJIT bridge remains for reference but is not used from buildDispatch.
 */
final class TryCatchRuntimeShrinkTest extends TestCase
{
    public function testTryCatchHelperUsesLlvmInstanceOfForCatchArms(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString('emitCatchTypesMatchI1', $source);
        $this->assertStringContainsString('emitInstanceOf($thrownVar, $typeName)', $source);
        $this->assertStringNotContainsString('TryCatchRuntime::callEncodedTypesMatch', $source);
        $this->assertStringNotContainsString('$singleArm', $source);
        $this->assertStringNotContainsString('try_catch_type_next_', $source);
    }

    public function testTryCatchRuntimeLinksTryCatchJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TryCatchRuntime.php');
        $this->assertStringContainsString('TryCatchJitHelper', $source);
        $this->assertStringContainsString('__trycatch__encodedTypesMatch', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
    }

    public function testTryCatchJitHelperDelegatesToVmTryCatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/TryCatchJitHelper.php');
        $this->assertStringContainsString('VmTryCatch::encodedTypesMatchClassName', $source);
        $this->assertStringContainsString('getActiveContext', $source);
    }

    public function testCatchableClassErrorReopensInsertBlockBeforeFallbackRaise(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString(
            "BasicBlockHelper::ensureOpenInsertBlock(\$context, 'catchable_error_resume')",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/ensureOpenInsertBlock\\(\\\$context, 'catchable_error_resume'\\).*?resolveThrowHandler\\(\\\$context\\)/s",
            $source
        );
    }
}
