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
        $this->assertStringContainsString('VmMetaphone.php', $source);
        $this->assertStringContainsString('HELPER_BUNDLE', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
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
        $this->assertSame('PRKRMNK', VmMetaphone::encode('programming', 0));
    }

    /** php-src string.c — negative max_phonemes is ValueError, not LogicException (#29304). */
    public function testNegativeMaxPhonemesThrowsValueError(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            'metaphone(): Argument #2 ($max_phonemes) must be greater than or equal to 0'
        );
        VmMetaphone::encode('test', -1);
    }

    /** NestedJIT compound summed index assign is a silent no-op → AOT SIGKILL (#26815). */
    public function testVmMetaphoneAvoidsCompoundSummedIndexAdvance(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmMetaphone.php');
        // Strip docblocks so the forbidden pattern is not matched inside comments.
        $code = (string) preg_replace('#/\*\*.*?\*/#s', '', $source);
        $this->assertStringNotContainsString('$wIdx += 1 + $skip', $code);
        $this->assertStringNotContainsString('$wIdx += 2', $code);
        $this->assertStringContainsString('advanceIdx', $source);
        $this->assertStringContainsString('++$wIdx', $source);
    }

    public function testSpineBundleOmitsDeletedJitMetaphone(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitMetaphone.php', $spine);
        $this->assertStringContainsString('MetaphoneJitHelper.php', $spine);
        $this->assertStringContainsString('StringMetaphone.php', $spine);
    }
}
