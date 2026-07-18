<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HashEqualsJitHelper;
use PHPCompiler\ext\standard\VmHash;
use PHPUnit\Framework\TestCase;

/** hash_equals() JIT routes through HashEqualsJitHelper PHP for embed + user-script AOT (#9164, #20469). */
final class StringHashEqualsRuntimeShrinkTest extends TestCase
{
    public function testStringHashEqualsUsesJitHelperNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashEquals.php');
        $this->assertStringContainsString('HashEqualsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('hash_equals_bridge_entry', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('JitHashEqualsKernel', $source);
        $this->assertStringNotContainsString('hash_equals_kernel_entry', $source);
        $this->assertStringNotContainsString('hash_equals_deferred_stub', $source);
        $this->assertStringNotContainsString('hash_equals_loop_head', $source);
        $this->assertStringNotContainsString('emitEqualsBody', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/hash/JitHashEqualsKernel.php');
    }

    public function testHashEqualsJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HashEqualsJitHelper.php');
        $this->assertStringContainsString('isset($known[$knownLen])', $source);
        $this->assertStringNotContainsString('VmHash::equals(', $source);
        $this->assertStringNotContainsString('strlen(', $source);
        $this->assertStringNotContainsString('ord(', $source);
        $this->assertTrue(HashEqualsJitHelper::equals('abc', 'abc'));
        $this->assertFalse(HashEqualsJitHelper::equals('abc', 'abd'));
        $this->assertFalse(HashEqualsJitHelper::equals('ab', 'abc'));
        $this->assertSame(
            VmHash::equals('secret', 'secret'),
            HashEqualsJitHelper::equals('secret', 'secret')
        );
        $this->assertSame(
            VmHash::equals("a\0b", "a\0c"),
            HashEqualsJitHelper::equals("a\0b", "a\0c")
        );
    }

    public function testSpineBundleIncludesHashEqualsPhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitHashEqualsKernel.php', $spine);
        $this->assertStringContainsString('HashEqualsJitHelper.php', $spine);
        $this->assertStringContainsString('StringHashEquals.php', $spine);
    }
}
