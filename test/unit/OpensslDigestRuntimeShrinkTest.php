<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * OpensslDigest NestedJIT via JitVmHelperLink::ensureCompiled (#22554 / peer #22519).
 */
final class OpensslDigestRuntimeShrinkTest extends TestCase
{
    public function testOpensslDigestRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslDigestRuntime.php');
        $this->assertStringContainsString('OpensslDigestJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testOpensslDigestJitHelperDelegatesToVmOpenssl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/openssl/OpensslDigestJitHelper.php');
        $this->assertStringContainsString('VmOpenssl::digest', $source);
    }
}
