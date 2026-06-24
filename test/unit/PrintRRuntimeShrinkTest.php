<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringPrintR JIT/AOT path uses PrintRJitHelper PHP, not StringPrintRJit monolith (#9190). */
final class PrintRRuntimeShrinkTest extends TestCase
{
    public function testStringPrintRUsesPrintRJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPrintR.php');
        $this->assertStringContainsString('PrintRJitHelper', $source);
        $this->assertStringContainsString('StringPrintRJit', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringPrintR must be a thin bridge (#9190)');
    }

    public function testPrintRJitHelperDelegatesToVmPrintR(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PrintRJitHelper.php');
        $this->assertStringContainsString('VmPrintR::formatVariable', $source);
    }

    public function testPrintRBuiltinUsesStringPrintRNotMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPrintR.php');
        $this->assertStringContainsString('StringPrintR', $source);
        $this->assertStringNotContainsString('StringPrintRJit', $source);
    }

    /** Issue #9190: spine must require PrintRJitHelper + thin bridge, keep standalone LLVM fallback. */
    public function testSpineBundleIncludesPrintRPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PrintRJitHelper.php', $spine);
        $this->assertStringContainsString('StringPrintR.php', $spine);
        $this->assertStringContainsString('VmPrintR.php', $spine);
        $this->assertStringContainsString('StringPrintRJit.php', $spine);
    }
}
