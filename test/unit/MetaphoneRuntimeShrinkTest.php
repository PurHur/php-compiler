<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MetaphoneJitHelper;
use PHPCompiler\ext\standard\VmMetaphone;
use PHPUnit\Framework\TestCase;

/** metaphone() JIT routes through MetaphoneJitHelper PHP not JitMetaphone LLVM (#13447). */
final class MetaphoneRuntimeShrinkTest extends TestCase
{
    public function testStringMetaphoneUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMetaphone.php');
        $this->assertStringContainsString('MetaphoneJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitMetaphone.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/metaphone.php');
        $this->assertStringContainsString('StringMetaphone::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_metaphone', $builtin);
        $this->assertStringNotContainsString('JitMetaphone', $builtin);
    }

    public function testMetaphoneJitHelperDelegatesToVmMetaphone(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MetaphoneJitHelper.php');
        $this->assertStringContainsString('VmMetaphone::encode', $source);

        $this->assertSame('NFTSBRJ', MetaphoneJitHelper::metaphoneArgv('Knightsbridge', 0));
        $this->assertSame('NFTS', MetaphoneJitHelper::metaphoneArgv('Knightsbridge', 4));
        $this->assertSame('ELR', VmMetaphone::encode('Euler', 0));
    }

    public function testSpineBundleOmitsDeletedJitMetaphone(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitMetaphone.php', $spine);
        $this->assertStringContainsString('MetaphoneJitHelper.php', $spine);
        $this->assertStringContainsString('StringMetaphone.php', $spine);
    }
}
