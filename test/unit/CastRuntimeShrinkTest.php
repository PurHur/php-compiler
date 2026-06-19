<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** CastHelper must route bool (array) through CastJitHelper PHP, not inline LLVM (#10046). */
final class CastRuntimeShrinkTest extends TestCase
{
    public function testCastArrayRuntimeUsesCastJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CastArrayRuntime.php');
        $this->assertStringContainsString('CastJitHelper', $source);
        $this->assertStringContainsString('boolYieldsEmptyArray', $source);
    }

    public function testCastHelperRoutesBoolArrayThroughCastArrayRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/CastHelper.php');
        $this->assertStringContainsString('CastArrayRuntime', $source);
        $this->assertStringContainsString('CastArrayValueBoxJit', $source);
        $this->assertStringContainsString('CastObjectValueBoxJit', $source);
        $this->assertLessThanOrEqual(130, substr_count($source, "\n") + 1);
    }

    public function testCastJitHelperAlignsWithCastSupport(): void
    {
        $this->assertTrue(\PHPCompiler\VM\CastJitHelper::boolYieldsEmptyArray(false));
        $this->assertFalse(\PHPCompiler\VM\CastJitHelper::boolYieldsEmptyArray(true));
    }
}
