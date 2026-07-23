<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\lz4\Lz4JitHelper;
use PHPCompiler\ext\lz4\VmLz4Native;
use PHPUnit\Framework\TestCase;

/**
 * StringLz4 helper compile routes through JitVmHelperLink (#22602 / peer #22575).
 */
final class Lz4RuntimeShrinkTest extends TestCase
{
    public function testStringLz4UsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLz4.php');
        $this->assertStringContainsString('VmLz4Native::compress', $source);
        $this->assertStringContainsString('VmLz4Native::uncompress', $source);
        $this->assertStringContainsString('Lz4JitHelper::compress', $source);
        $this->assertStringContainsString('Lz4JitHelper::uncompress', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testLz4JitHelperDelegatesToVmNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/lz4/Lz4JitHelper.php');
        $this->assertStringContainsString('VmLz4Native::compress', $source);
        $this->assertStringContainsString('VmLz4Native::uncompress', $source);
    }

    public function testLz4JitHelperMatchesVmNativeRoundTrip(): void
    {
        if (!VmLz4Native::available()) {
            self::markTestSkipped('liblz4 / FFI unavailable');
        }

        $native = VmLz4Native::compress('hello', 0);
        $this->assertIsString($native);
        $this->assertSame($native, Lz4JitHelper::compress('hello', 0));
        $this->assertSame('hello', VmLz4Native::uncompress($native));
        $this->assertSame('hello', Lz4JitHelper::uncompress($native));
    }
}
