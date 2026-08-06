<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #17335: settype() JIT/AOT routes in-place casts through SettypeJitHelper PHP — no inline LLVM monolith.
 *
 * @group aot-lint
 */
final class SettypeRuntimeShrinkTest extends TestCase
{
    public function testRuntimeShrinkRemovesSettypeC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_settype.c');

        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_settype.c', $linker);
        $this->assertStringNotContainsString('phpc_settype', $linker);

        $settype = (string) file_get_contents(__DIR__.'/../../../ext/standard/settype.php');
        $this->assertStringContainsString('JitSettype::invoke', $settype);
    }

    public function testJitSettypeDelegatesToSettypeRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitSettype.php');
        $this->assertStringContainsString('final class JitSettype', $source);
        $this->assertStringContainsString('SettypeRuntime::applyInPlace', $source);
        $this->assertStringContainsString('promoteNativeLvalueToValueBox', $source);
        $this->assertStringContainsString('tryFoldCompileTime', $source);
        $this->assertStringNotContainsString('convertInPlace', $source);
        $this->assertStringNotContainsString('emitTargetFromString', $source);
        $this->assertLessThanOrEqual(220, substr_count($source, "\n") + 1);
    }

    public function testSettypeRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/SettypeRuntime.php');
        $this->assertStringContainsString('SettypeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    /** Issue #27090: type name must be `%__string__*`, not raw cstr (`constantFromString`). */
    public function testSettypeRuntimePassesLoadedStringConstNotCstr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/SettypeRuntime.php');
        $this->assertStringContainsString('constantStringFromString', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString('constantFromString($typeName)', $source);
        $this->assertMatchesRegularExpression(
            '/constantStringFromString\(\$typeName\)/',
            $source
        );
    }

    public function testSettypeJitHelperDelegatesToVmSettype(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/SettypeJitHelper.php');
        $this->assertStringContainsString('VmSettype::apply', $source);
    }
}
