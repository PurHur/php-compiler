<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sodium NestedJIT via JitVmHelperLink::ensureCompiled (#23519 / peer #23498).
 */
final class SodiumRuntimeShrinkTest extends TestCase
{
    public function testStringSodiumUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSodium.php');
        $this->assertStringContainsString('SodiumJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testSpineBundleIncludesSodiumJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SodiumJitHelper.php', $spine);
        $this->assertStringContainsString('StringSodium.php', $spine);
    }
}
