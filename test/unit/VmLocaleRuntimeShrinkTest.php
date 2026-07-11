<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmLocale;
use PHPCompiler\ext\standard\VmLocalePure;
use PHPUnit\Framework\TestCase;

/** setlocale/localeconv/nl_langinfo without libc FFI (#13584, php-in-php). */
final class VmLocaleRuntimeShrinkTest extends TestCase
{
    public function testVmLocaleHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmLocale.php');
        $this->assertStringContainsString('VmLocalePure::', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('libc.so', $source);
    }

    public function testVmLocalePureHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmLocalePure.php');
        $this->assertStringContainsString('setlocale', $source);
        $this->assertStringContainsString('localeconv', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testLocaleconvWorksWithFfiDisabled(): void
    {
        if (!VmLocalePure::available()) {
            $this->markTestSkipped('host setlocale/localeconv unavailable');
        }

        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $ht = VmLocale::localeconv();
            $decimal = $ht->find('decimal_point')?->toString();
            $this->assertIsString($decimal);
            $this->assertNotSame('', $decimal);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testSetlocaleQueryWorksWithFfiDisabled(): void
    {
        if (!VmLocalePure::available()) {
            $this->markTestSkipped('host setlocale/localeconv unavailable');
        }

        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $result = VmLocale::setlocale(\defined('LC_ALL') ? (int) \constant('LC_ALL') : 6, []);
            $this->assertIsString($result);
            $this->assertNotSame('', $result);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
