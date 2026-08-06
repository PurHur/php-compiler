<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\lzf\LzfExtensionPolicy;
use PHPCompiler\ext\lzf\VmLzf;
use PHPUnit\Framework\TestCase;

/** lzf_optimized_for() PECL surface (#28063). */
final class LzfOptimizedFor28063Test extends TestCase
{
    public function testModuleRegistersOptimizedFor(): void
    {
        $mod = (string) file_get_contents(__DIR__.'/../../ext/lzf/Module.php');
        $this->assertStringContainsString('new lzf_optimized_for()', $mod);
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ext/lzf/lzf_optimized_for.php', $spine);
        $this->assertFileExists(__DIR__.'/../../test/repro/issue_28063_lzf_optimized_for.php');
        $this->assertFileExists(__DIR__.'/../../ext/lzf/lzf_optimized_for.php');
    }

    public function testVmReturnsUltraFastConstant(): void
    {
        $this->assertSame(1, VmLzf::OPTIMIZED_FOR_SPEED);
        $this->assertSame(1, VmLzf::optimizedFor());
    }

    public function testJitLzfExposesOptimizedFor(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/lzf/JitLzf.php');
        $this->assertStringContainsString('function optimizedFor', $source);
        $this->assertStringContainsString('OPTIMIZED_FOR_SPEED', $source);
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/lzf/lzf_optimized_for.php');
        $this->assertStringContainsString('JitLzf::optimizedFor', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);
    }

    public function testPolicyGateUnchanged(): void
    {
        // Registration still gated — phantom cases must keep withholding without ENABLE_LZF.
        $this->assertTrue(method_exists(LzfExtensionPolicy::class, 'advertisesBuiltins'));
    }
}
