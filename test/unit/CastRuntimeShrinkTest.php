<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** CastHelper routes casts through PHP-backed helpers, not monolithic LLVM (#10046, #10244). */
final class CastRuntimeShrinkTest extends TestCase
{
    public function testCastArrayRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CastArrayRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('CastJitHelper', $source);
        $this->assertStringContainsString('boolYieldsEmptyArray', $source);
    }

    public function testCastHelperIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/CastHelper.php');
        $this->assertStringContainsString('CastArrayNativeJit', $source);
        $this->assertStringContainsString('CastObjectNativeJit', $source);
        $this->assertStringContainsString('CastUnsetJit', $source);
        $this->assertStringContainsString('CastArrayValueBoxJit', $source);
        $this->assertLessThanOrEqual(45, substr_count($source, "\n") + 1);
    }

    public function testCastJitHelperAlignsWithCastSupport(): void
    {
        $this->assertTrue(\PHPCompiler\VM\CastJitHelper::boolYieldsEmptyArray(false));
        $this->assertFalse(\PHPCompiler\VM\CastJitHelper::boolYieldsEmptyArray(true));
    }
}
