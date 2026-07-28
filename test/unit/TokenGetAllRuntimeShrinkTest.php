<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TokenGetAll NestedJIT via JitVmHelperLink::ensureCompiled (#24427 / peer #24417).
 */
final class TokenGetAllRuntimeShrinkTest extends TestCase
{
    public function testTokenGetAllUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TokenGetAll.php');
        $this->assertStringContainsString('TokenGetAllJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(60, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesTokenGetAllJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('TokenGetAllJitHelper.php', $spine);
        $this->assertStringContainsString('lib/JIT/Builtin/TokenGetAll.php', $spine);
    }
}
