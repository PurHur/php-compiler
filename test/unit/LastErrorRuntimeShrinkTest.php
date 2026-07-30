<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ErrorLastJitHelper;
use PHPUnit\Framework\TestCase;

/** LastErrorRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#25318 / peer #25269). */
final class LastErrorRuntimeShrinkTest extends TestCase
{
    public function testLastErrorRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LastErrorRuntime.php');
        $this->assertStringContainsString('ErrorLastJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('phpc_last_error_active', $source);
        $this->assertStringNotContainsString('phpc_last_error_message', $source);
        $this->assertStringNotContainsString('G_ACTIVE', $source);
        $this->assertStringNotContainsString('LastErrorRuntimeLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/LastErrorRuntimeLlvm.php');
    }

    public function testErrorLastJitHelperSemantics(): void
    {
        ErrorLastJitHelper::clear();
        $this->assertFalse(ErrorLastJitHelper::isActive());

        ErrorLastJitHelper::record(2, 'test message', 'file.php', 42);
        $this->assertTrue(ErrorLastJitHelper::isActive());
        $this->assertSame(2, ErrorLastJitHelper::getType());
        $this->assertSame('test message', ErrorLastJitHelper::getMessage());
        $this->assertSame('file.php', ErrorLastJitHelper::getFile());
        $this->assertSame(42, ErrorLastJitHelper::getLine());

        ErrorLastJitHelper::clear();
        $this->assertFalse(ErrorLastJitHelper::isActive());
    }

    public function testSpineBundleIncludesErrorLastJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ErrorLastJitHelper.php', $spine);
        $this->assertStringContainsString('LastErrorRuntime.php', $spine);
    }
}
