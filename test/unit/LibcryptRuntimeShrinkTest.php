<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LibcryptJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * __compiler_libcrypt NestedJIT via JitVmHelperLink::ensureCompiled (#22886 / #29545).
 */
final class LibcryptRuntimeShrinkTest extends TestCase
{
    public function testLibcryptUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LibcryptRuntime.php');
        $this->assertStringContainsString('LibcryptJitHelper', $bridge);
        $this->assertStringContainsString('__compiler_libcrypt', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testLibcryptJitHelperUsesCryptBuiltinNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LibcryptJitHelper.php');
        $this->assertStringContainsString('\\crypt(', $source);
        $this->assertStringNotContainsString('phpc_libcrypt_kernel', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_libcrypt_kernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitLibcryptKernel.php');
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitLibcryptKernel.php');
        $this->assertStringContainsString('__compiler_libc_crypt', $kernel);
        $this->assertStringNotContainsString("lookupFunction('crypt')", $kernel);
        $crypt = (string) file_get_contents(__DIR__.'/../../ext/standard/crypt.php');
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $crypt);
        $this->assertStringContainsString('JitLibcryptKernel::invoke', $crypt);

        if (!\function_exists('crypt')) {
            $this->markTestSkipped('host crypt() unavailable');
        }

        $salt = '$1$phpcomp$';
        $expected = \crypt('secret', $salt);
        if (!\is_string($expected) || '' === $expected || '*' === $expected[0]) {
            $this->markTestSkipped('host crypt() failed for DES/MD5 salt');
        }

        $this->assertSame($expected, LibcryptJitHelper::cryptArgv('secret', $salt));
        $this->assertNull(LibcryptJitHelper::cryptArgv('secret', ''));
    }

    public function testContextWhitelistsCryptNotLibcryptKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertMatchesRegularExpression("/'crypt'\\s*,/", $source);
        $this->assertStringNotContainsString("'phpc_libcrypt_kernel'", $source);
    }

    public function testSpineBundleIncludesLibcryptJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LibcryptJitHelper.php', $spine);
        $this->assertStringContainsString('LibcryptRuntime.php', $spine);
        $this->assertStringNotContainsString('phpc_libcrypt_kernel.php', $spine);
        $this->assertStringContainsString('JitLibcryptKernel.php', $spine);
    }
}
