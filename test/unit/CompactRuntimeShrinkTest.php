<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** compact() C runtime shrink (#5493). */
final class CompactRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcCompactCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_compact.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_compact.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/phpc_compact.c');
    }

    public function testCompactLoweringUsesPhpScopeBuiltinHelper(): void
    {
        $source = file_get_contents($this->repoRoot.'/ext/standard/compact_.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('ScopeBuiltinHelper', $source);
        $this->assertStringContainsString('VmScope::compact', $source);
        $this->assertStringNotContainsString('phpc_compact', $source);
    }
}
