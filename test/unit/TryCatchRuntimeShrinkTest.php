<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** TryCatch catch-arm type matching routes through TryCatchJitHelper PHP (#16247, #9663). */
final class TryCatchRuntimeShrinkTest extends TestCase
{
    public function testTryCatchHelperUsesRuntimeNotEmitInstanceOfLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString('TryCatchRuntime::callEncodedTypesMatch', $source);
        $this->assertStringNotContainsString('try_catch_type_next_', $source);
        $this->assertStringNotContainsString('emitInstanceOf($context, $thrownVar, $typeName)', $source);
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
