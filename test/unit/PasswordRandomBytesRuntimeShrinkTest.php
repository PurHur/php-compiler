<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PasswordRandomBytesRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#22313 / peer #22300).
 */
final class PasswordRandomBytesRuntimeShrinkTest extends TestCase
{
    public function testPasswordRandomBytesRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PasswordRandomBytesRuntime.php');
        $this->assertStringContainsString('RandomBytesJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testPasswordCryptoRuntimeStillLinksPasswordRandomBytes(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PasswordCryptoRuntime.php');
        $this->assertStringContainsString('PasswordRandomBytesRuntime::ensureLinked', $source);
    }

    public function testJitPasswordRandomBytesDelegatesToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPasswordRandomBytes.php');
        $this->assertStringContainsString('PasswordRandomBytesRuntime::ensureLinked', $source);
    }
}
