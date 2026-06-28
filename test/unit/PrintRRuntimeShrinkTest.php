<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringPrintR JIT/AOT path uses PrintRJitHelper PHP, not StringPrintRJit monolith (#9190, #13240). */
final class PrintRRuntimeShrinkTest extends TestCase
{
    public function testStringPrintRUsesPrintRJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPrintR.php');
        $this->assertStringContainsString('PrintRJitHelper', $source);
        $this->assertStringNotContainsString('StringPrintRJit', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringPrintR must be a thin bridge (#9190)');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringPrintRJit.php');
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

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPrintR.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
    }

    /** Issue #9190 / #13240: spine must require PrintRJitHelper + thin bridge. */
    public function testSpineBundleIncludesPrintRPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PrintRJitHelper.php', $spine);
        $this->assertStringContainsString('StringPrintR.php', $spine);
        $this->assertStringContainsString('VmPrintR.php', $spine);
        $this->assertStringNotContainsString('StringPrintRJit.php', $spine);
    }
}
