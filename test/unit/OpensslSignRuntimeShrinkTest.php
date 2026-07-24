<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * OpensslSign NestedJIT via JitVmHelperLink::ensureCompiled (#22911 / peer #22683).
 */
final class OpensslSignRuntimeShrinkTest extends TestCase
{
    public function testOpensslSignRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslSignRuntime.php');
        $this->assertStringContainsString('OpensslSignJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testOpensslSignJitHelperDelegatesToVmOpensslSignNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/openssl/OpensslSignJitHelper.php');
        $this->assertStringContainsString('VmOpensslSignNative', $source);
    }
}
