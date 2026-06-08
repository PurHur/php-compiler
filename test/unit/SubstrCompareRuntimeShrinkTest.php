<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5260: substr_compare() JIT/AOT lowers from ext/standard/VmString — no phpc_substr_compare.c.
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

    public function testRuntimeShrinkRemovesSubstrCompareC(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_substr_compare.c');

        $linker = (string) file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_substr_compare.c', $linker);
        $this->assertStringNotContainsString('phpc_substr_compare', $linker);

        $jit = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringSubstrCompareJit.php');
        $this->assertStringContainsString('final class StringSubstrCompareJit', $jit);
        $this->assertStringContainsString('VmString::substr_compare', $jit);

        $builtin = (string) file_get_contents($this->repoRoot.'/ext/standard/substr_compare.php');
        $this->assertStringContainsString('StringSubstrCompare::ensureLinked', $builtin);
        $this->assertStringContainsString('no phpc_substr_compare.c', $builtin);
    }
}
