<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SprintfJitHelper;
use PHPCompiler\ext\standard\VsprintfJitHelper;
use PHPUnit\Framework\TestCase;

/** vsprintf() JIT — type guard extracted; format tail via __compiler_sprintf → SprintfJitHelper PHP (#15989). */
final class VsprintfRuntimeShrinkTest extends TestCase
{
    public function testJitVsprintfUsesCompilerSprintfBridgeAndExtractedArrayArgGuard(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitVsprintf.php');
        $this->assertStringContainsString('__compiler_sprintf', $source);
        $this->assertStringContainsString('StringFormat::ensureLinked', $source);
        $this->assertStringNotContainsString('requireValuesArrayArg', $source);
        $this->assertStringContainsString('JitVsprintfArrayArg::requireValues', $source);
        $this->assertStringNotContainsString('emitBoxedValuesTypeError', $source);
        $this->assertStringNotContainsString('__mm__malloc', $source);
        $this->assertStringNotContainsString('vsprintf_loop', $source);
        $this->assertLessThanOrEqual(40, substr_count($source, "\n") + 1);
    }

    public function testStringVsprintfUsesJitHelperNotDirectSprintfLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVsprintf.php');
        $this->assertStringContainsString('VsprintfJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
    }

    public function testVsprintfJitHelperDelegatesToSprintfJitHelper(): void
    {
        $blob = \chr(1).\pack('q', 7).\chr(1).\pack('q', 42);
        $this->assertSame(
            SprintfJitHelper::sprintfArgv('%03d-%d', $blob),
            VsprintfJitHelper::formatPackedArgv('%03d-%d', $blob)
        );
    }

    public function testJitVsprintfArrayArgGuardExtractedFromMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitVsprintfArrayArg.php');
        $this->assertStringContainsString('requireValues', $source);
        $this->assertStringNotContainsString('__compiler_sprintf', $source);
    }

    public function testSpineBundleIncludesVsprintfJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VsprintfJitHelper.php', $spine);
        $this->assertStringContainsString('StringVsprintf.php', $spine);
        $this->assertStringContainsString('JitVsprintfArrayArg.php', $spine);
    }
}
