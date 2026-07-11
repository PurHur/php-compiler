<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\SubstrCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5260 / #13536: substr_compare() JIT routes through SubstrCompareJitHelper PHP — no phpc_substr_compare.c.
 *
 * @group aot-lint
 */
final class SubstrCompareRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testRuntimeShrinkRemovesSubstrCompareCAndLlvmMonolith(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_substr_compare.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/StringSubstrCompareJit.php');

        $linker = (string) file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_substr_compare.c', $linker);
        $this->assertStringNotContainsString('phpc_substr_compare', $linker);

        $bridge = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringSubstrCompare.php');
        $this->assertStringContainsString('SubstrCompareJitHelper', $bridge);
        $this->assertStringNotContainsString('final class StringSubstrCompareJit', $bridge);

        $builtin = (string) file_get_contents($this->repoRoot.'/ext/standard/substr_compare.php');
        $this->assertStringContainsString('StringSubstrCompare::ensureLinked', $builtin);
        $this->assertStringContainsString('no phpc_substr_compare.c', $builtin);
    }

    public function testSubstrCompareJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/SubstrCompareJitHelper.php');
        $this->assertStringContainsString('VmString::substr_compare', $source);

        $expected = VmString::substr_compare('abc', 'ab', 0, 2);
        $this->assertSame($expected, SubstrCompareJitHelper::substrCompareArgv('abc', 'ab', 0, 2, false));
        $this->assertSame(0, $expected);
    }

    public function testSpineBundleIncludesSubstrCompareJitHelper(): void
    {
        $spine = (string) file_get_contents($this->repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('StringSubstrCompareJit.php', $spine);
        $this->assertStringContainsString('SubstrCompareJitHelper.php', $spine);
    }
}
