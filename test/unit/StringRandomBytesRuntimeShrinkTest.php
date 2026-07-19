<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\RandomBytesJitHelper;
use PHPCompiler\JIT\Builtin\StringRandomBytes;
use PHPUnit\Framework\TestCase;

/**
 * JIT random_bytes: always RandomBytesJitHelper via JitVmHelperLink — no user-script null stub (#9149, #21186).
 */
final class StringRandomBytesRuntimeShrinkTest extends TestCase
{
    public function testStringRandomBytesAlwaysUsesHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('RandomBytesJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('shouldUseUserScriptThinStub', $source);
        $this->assertStringNotContainsString('implementUserScriptThinStub', $source);
        $this->assertStringNotContainsString('rb_user_stub', $source);
        $this->assertStringNotContainsString("lookupFunction('open')", $source);
        $this->assertStringNotContainsString("lookupFunction('read')", $source);
        $this->assertStringNotContainsString('/dev/urandom', $source);
        $this->assertLessThan(100, \substr_count($source, "\n") + 1);
    }

    public function testRandomBytesJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RandomBytesJitHelper.php');
        $this->assertStringContainsString('phpc_random_bytes_kernel', $source);
        $this->assertStringNotContainsString('VmRandomPure::randomBytes', $source);
    }

    public function testJitRandomBytesKernelIsLibcUrandomLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytesKernel.php');
        $this->assertStringContainsString("lookupFunction('open')", $source);
        $this->assertStringContainsString("lookupFunction('read')", $source);
        $this->assertStringContainsString('/dev/urandom', $source);
        $this->assertStringContainsString('LibcExtern::register', $source);
    }

    public function testJitRandomBytesDelegatesToCompilerHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytes.php');
        $this->assertStringContainsString('__compiler_random_bytes', $source);
    }

    public function testRandomBytesJitHelperReturnsRequestedLength(): void
    {
        if (!\function_exists('phpc_random_bytes_kernel')) {
            $this->markTestSkipped('phpc_random_bytes_kernel requires compiler runtime');
        }
        if (!\is_readable('/dev/urandom')) {
            $this->markTestSkipped('/dev/urandom not readable');
        }
        $bytes = RandomBytesJitHelper::randomBytes(16);
        $this->assertSame(16, \strlen($bytes));
    }

    public function testImplementDefinesRandomBytesBridge(): void
    {
        if (!class_exists(StringRandomBytes::class)) {
            require_once __DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php';
        }
        $this->assertTrue(method_exists(StringRandomBytes::class, 'implement'));
        $this->assertTrue(method_exists(StringRandomBytes::class, 'ensureLinked'));
    }

    public function testSpineBundleIncludesRandomBytesKernelAndHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RandomBytesJitHelper.php', $spine);
        $this->assertStringContainsString('JitRandomBytesKernel.php', $spine);
        $this->assertStringContainsString('phpc_random_bytes_kernel.php', $spine);
        $this->assertStringContainsString('StringRandomBytes.php', $spine);
    }
}
