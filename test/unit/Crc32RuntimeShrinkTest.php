<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Crc32JitHelper;
use PHPCompiler\ext\standard\VmCrc32;
use PHPCompiler\ext\standard\VmCrc32c;
use PHPUnit\Framework\TestCase;

/** crc32()/crc32c() JIT routes through NestedJIT-safe Crc32JitHelper PHP (#15759, #27077). */
final class Crc32RuntimeShrinkTest extends TestCase
{
    public function testJitCrc32DelegatesToRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitCrc32.php');
        $this->assertStringContainsString('Crc32Runtime::invokeCrc32', $source);
        $this->assertStringNotContainsString('JitCrcCore', $source);
    }

    public function testJitCrc32cDelegatesToRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitCrc32c.php');
        $this->assertStringContainsString('Crc32Runtime::invokeCrc32c', $source);
        $this->assertStringNotContainsString('JitCrcCore', $source);
    }

    public function testCrc32CoreFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitCrcCore.php');
    }

    public function testCrc32JitHelperIsNestedJitSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Crc32JitHelper.php');
        // Call sites only — docblock may name the VM SSOT / banned builtins for humans.
        $this->assertStringNotContainsString('VmCrc32::', $source);
        $this->assertStringNotContainsString('VmCrc32c::', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('\\ord(', $source);
        $this->assertStringNotContainsString('\\strlen(', $source);
    }

    public function testCrc32JitHelperMatchesVmSsot(): void
    {
        $this->assertSame(VmCrc32::compute('abc', 0), Crc32JitHelper::crc32Argv('abc', 0));
        $this->assertSame(VmCrc32c::compute('abc'), Crc32JitHelper::crc32cArgv('abc'));
        $this->assertSame(4282364586, Crc32JitHelper::crc32Argv('php-compiler', 0));
        $seed = VmCrc32::compute('f', 0);
        $this->assertSame(VmCrc32::compute('oo', $seed), Crc32JitHelper::crc32Argv('oo', $seed));
    }
}
