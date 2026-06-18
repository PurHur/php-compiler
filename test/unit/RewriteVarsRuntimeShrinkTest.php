<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** RewriteVarsRuntime must route through OutputRewriteVarsJitHelper PHP, not LLVM globals (#9753). */
final class RewriteVarsRuntimeShrinkTest extends TestCase
{
    public function testRewriteVarsRuntimeUsesOutputRewriteVarsJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/RewriteVarsRuntime.php');
        $this->assertStringContainsString('OutputRewriteVarsJitHelper', $source);
        $this->assertStringNotContainsString("addGlobal(\$htPtrTy, 'phpc_rewrite_vars')", $source);
        $this->assertStringNotContainsString('HashTableHelper', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyString', $source);
    }

    public function testResponseContextDelegatesToOutputRewriteVarsJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Web/ResponseContext.php');
        $this->assertStringContainsString('OutputRewriteVarsJitHelper', $source);
        $this->assertStringNotContainsString('$rewriteVars', $source);
    }

    public function testJitOutputRewriteVarsUsesRewriteVarsRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitOutputRewriteVars.php');
        $this->assertStringContainsString('RewriteVarsRuntime', $source);
    }
}
