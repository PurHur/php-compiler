<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HashEqualsJitHelper;
use PHPCompiler\ext\standard\VmHash;
use PHPUnit\Framework\TestCase;

/** StringHashEquals: PHP helper embed; thin kernel for standalone AOT (#9164, #20065). */
final class StringHashEqualsRuntimeShrinkTest extends TestCase
{
    public function testStringHashEqualsRoutesThroughHashEqualsJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashEquals.php');
        $this->assertStringContainsString('HashEqualsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('JitHashEqualsKernel', $source);
        $this->assertStringContainsString('hash_equals_bridge_entry', $source);
        $this->assertStringContainsString('hash_equals_kernel_entry', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('hash_equals_deferred_stub', $source);
        $this->assertStringNotContainsString('hash_equals_loop_head', $source);
        $this->assertStringNotContainsString('stringData', $source);
        $this->assertFileExists(__DIR__.'/../../ext/hash/JitHashEqualsKernel.php');
        $this->assertLessThan(130, \substr_count($source, "\n") + 1);
    }

    public function testKernelEmitsTimingSafeXorNotStub(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/hash/JitHashEqualsKernel.php');
        $this->assertStringContainsString('emitEqualsBody', $kernel);
        $this->assertStringContainsString('hash_equals_loop_head', $kernel);
        $this->assertStringContainsString('xor', $kernel);
        $this->assertStringNotContainsString('hash_equals_deferred_stub', $kernel);
        $this->assertStringNotContainsString('constInt(0, false)); // always false', $kernel);
    }

    public function testSpineBundleIncludesHashEqualsKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitHashEqualsKernel.php', $spine);
        $this->assertStringContainsString('StringHashEquals.php', $spine);
        $this->assertStringContainsString('HashEqualsJitHelper.php', $spine);
    }

    public function testHashEqualsJitHelperDelegatesToVmHash(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HashEqualsJitHelper.php');
        $this->assertStringContainsString('VmHash::equals', $source);
    }

    public function testHashEqualsJitHelperSemanticsMatchVmHash(): void
    {
        $this->assertTrue(HashEqualsJitHelper::equals('abc', 'abc'));
        $this->assertFalse(HashEqualsJitHelper::equals('abc', 'abd'));
        $this->assertFalse(HashEqualsJitHelper::equals('ab', 'abc'));
        $this->assertSame(
            VmHash::equals('secret', 'secret'),
            HashEqualsJitHelper::equals('secret', 'secret')
        );
    }
}
