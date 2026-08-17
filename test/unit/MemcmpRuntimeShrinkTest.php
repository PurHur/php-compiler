<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * Internal memcmp stays PHP-in-PHP via NCompareJitHelper (#15364).
 * Userland memcmp() was a phantom vs php-src and was unregistered (#25359).
 */
final class MemcmpRuntimeShrinkTest extends TestCase
{
    public function testStrncmpUsesPhpBridgeNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/strncmp.php');
        $this->assertStringContainsString('StringStrncmp::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('strncmp')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNCompare.php');
        $this->assertStringContainsString('NCompareJitHelper', $bridge);
        $this->assertStringContainsString('phpc_strncmp', $bridge);
        $this->assertStringContainsString('strncmpArgv', $bridge);
    }

    public function testJitHelpersDelegateToVmString(): void
    {
        $this->assertSame(VmString::memcmp('abc', 'abd', 3), NCompareJitHelper::memcmpArgv('abc', 'abd', 3));
        $this->assertSame(VmString::strncmp('Ab', 'ab', 1), NCompareJitHelper::strncmpArgv('Ab', 'ab', 1));
    }

    public function testSpineBundleIncludesNCompareJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('NCompareJitHelper.php', $spine);
        $this->assertStringContainsString('StringNCompare.php', $spine);
        $this->assertStringNotContainsString('ext/standard/memcmp.php', $spine);
    }

    public function testUserlandMemcmpWrapperRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/memcmp.php');
    }

    public function testVmStringCompareKeepsLibcMemcmpForInternalLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmStringCompare.php');
        $this->assertStringContainsString("lookupFunction('memcmp')", $source);
        $this->assertStringContainsString('LibcExtern::ensureMemcmpDecl', $source);
        $this->assertStringContainsString('#31954', $source);
    }
}
