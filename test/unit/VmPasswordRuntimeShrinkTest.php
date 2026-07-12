<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmPassword;
use PHPCompiler\ext\standard\VmPasswordNative;
use PHPCompiler\ext\standard\VmPasswordPure;
use PHPUnit\Framework\TestCase;

/** VmPassword bcrypt/crypt without libcrypt FFI (#14182, php-in-php). */
final class VmPasswordRuntimeShrinkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('__compiler_libcrypt')) {
            eval('function __compiler_libcrypt(string $key, string $salt): ?string {
                if (!\\function_exists("crypt")) { return null; }
                $result = \\crypt($key, $salt);
                return \\is_string($result) && "" !== $result ? $result : null;
            }');
        }
        if (!\function_exists('__compiler_password_random_bytes')) {
            eval('function __compiler_password_random_bytes(int $length): string {
                return \\random_bytes($length);
            }');
        }
    }

    public function testVmPasswordNativeHasNoLibcryptFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPasswordNative.php');
        $this->assertStringContainsString('VmPasswordPure::', $source);
        $this->assertStringNotContainsString('libcrypt.so', $source);
        $this->assertStringNotContainsString('char *crypt(const char *key', $source);
        $this->assertStringNotContainsString('private static function ffi(', $source);
    }

    public function testVmPasswordPureHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPasswordPure.php');
        $this->assertStringContainsString('__compiler_libcrypt', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testJitLibcryptHasNoLibcCryptLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitLibcrypt.php');
        $this->assertStringContainsString('LibcryptRuntime::', $source);
        $this->assertStringNotContainsString('LibcryptThinRuntime', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/LibcryptThinRuntime.php');
    }

    public function testBcryptWorksWithFfiDisabled(): void
    {
        if (!VmPasswordPure::available()) {
            $this->markTestSkipped('host crypt() unavailable');
        }

        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $hash = VmPassword::hash('secret', VmPassword::PASSWORD_BCRYPT, ['cost' => 4]);
            $this->assertIsString($hash);
            $this->assertTrue(str_starts_with($hash, '$2y$'));
            $this->assertTrue(VmPassword::verify('secret', $hash));
            $this->assertFalse(VmPassword::verify('wrong', $hash));

            $crypt = VmPassword::crypt('test', '$2y$04$abcdefghijklmnopqrstuu');
            $this->assertNotSame('*0', $crypt);
            $this->assertStringStartsWith('$2y$', $crypt);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testVmPasswordNativeAvailableViaPurePath(): void
    {
        if (!VmPasswordPure::available()) {
            $this->markTestSkipped('host crypt() unavailable');
        }
        $this->assertTrue(VmPasswordNative::available());
    }
}
