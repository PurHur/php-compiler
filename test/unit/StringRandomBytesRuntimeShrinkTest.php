<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\RandomBytesJitHelper;
use PHPCompiler\JIT\Builtin\StringRandomBytes;
use PHPUnit\Framework\TestCase;

/**
 * JIT random_bytes: @random_bytes NestedJIT leaf + /dev/urandom LLVM — no kernel Internal
 * (#9149, #21186, #29531).
 */
final class StringRandomBytesRuntimeShrinkTest extends TestCase
{
    public function testStringRandomBytesAlwaysUsesHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('RandomBytesJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitRandomBytesKernel', $source);
        $this->assertStringNotContainsString('shouldUseUserScriptThinStub', $source);
        $this->assertStringNotContainsString('implementUserScriptThinStub', $source);
        $this->assertStringNotContainsString('rb_user_stub', $source);
        $this->assertStringNotContainsString("lookupFunction('open')", $source);
        $this->assertStringNotContainsString("lookupFunction('read')", $source);
        $this->assertStringNotContainsString('/dev/urandom', $source);
        $this->assertStringNotContainsString('phpc_random_bytes_kernel', $source);
        $this->assertLessThan(100, \substr_count($source, "\n") + 1);
    }

    public function testRandomBytesJitHelperUsesHostRandomBytesNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RandomBytesJitHelper.php');
        $this->assertStringContainsString('public static function randomBytes(int $length): string', $source);
        $this->assertMatchesRegularExpression('/return\s+\\\\random_bytes\s*\(\s*\$length\s*\)/', $source);
        $this->assertStringNotContainsString('phpc_random_bytes_kernel', $source);
        $this->assertStringNotContainsString('VmRandomPure::randomBytes', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_random_bytes_kernel.php');

        if (!\function_exists('random_bytes') || !\is_readable('/dev/urandom')) {
            $this->markTestSkipped('host random_bytes /dev/urandom unavailable');
        }
        $bytes = RandomBytesJitHelper::randomBytes(16);
        $this->assertSame(16, \strlen($bytes));
    }

    public function testJitRandomBytesKernelIsLibcUrandomLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytesKernel.php');
        $this->assertStringContainsString("lookupFunction('open')", $source);
        $this->assertStringContainsString("lookupFunction('read')", $source);
        $this->assertStringContainsString('/dev/urandom', $source);
        $this->assertStringContainsString('LibcExtern::register', $source);
        $this->assertStringNotContainsString('phpc_random_bytes_kernel', $source);
    }

    public function testJitRandomBytesNestedLeafUsesKernelNotBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytes.php');
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitRandomBytesKernel::invoke', $source);
        $this->assertStringContainsString('__compiler_random_bytes', $source);
        $this->assertStringNotContainsString('phpc_random_bytes_kernel', $source);
    }

    public function testModuleNoLongerRegistersKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString('phpc_random_bytes_kernel', $source);
        $this->assertStringContainsString('new random_bytes()', $source);
    }

    public function testNestedJitAllowlistsRandomBytesBuiltinNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'random_bytes'", $source);
        $this->assertStringContainsString('#29531', $source);
        $this->assertStringNotContainsString('phpc_random_bytes_kernel', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testImplementDefinesRandomBytesBridge(): void
    {
        if (!class_exists(StringRandomBytes::class)) {
            require_once __DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php';
        }
        $this->assertTrue(method_exists(StringRandomBytes::class, 'implement'));
        $this->assertTrue(method_exists(StringRandomBytes::class, 'ensureLinked'));
    }

    public function testSpineBundleIncludesRandomBytesHelperNotKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RandomBytesJitHelper.php', $spine);
        $this->assertStringContainsString('JitRandomBytesKernel.php', $spine);
        $this->assertStringContainsString('StringRandomBytes.php', $spine);
        $this->assertStringNotContainsString('phpc_random_bytes_kernel.php', $spine);
    }
}
