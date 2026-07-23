<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * OpensslEncrypt NestedJIT via JitVmHelperLink::ensureCompiled (#22683 / peer #22554).
 */
final class OpensslEncryptRuntimeShrinkTest extends TestCase
{
    public function testOpensslEncryptRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslEncryptRuntime.php');
        $this->assertStringContainsString('OpensslEncryptJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testOpensslEncryptJitHelperDelegatesToVmOpensslCipherNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/openssl/OpensslEncryptJitHelper.php');
        $this->assertStringContainsString('VmOpensslCipherNative', $source);
    }
}
