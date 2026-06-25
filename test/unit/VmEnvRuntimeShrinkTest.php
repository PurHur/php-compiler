<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmEnv;
use PHPCompiler\ext\standard\VmEnvEnvironNative;
use PHPCompiler\ext\standard\VmEnvPutenvNative;
use PHPUnit\Framework\TestCase;

/** VmEnv putenv/getenv without host Zend or libc FFI (#8086, #5345, #8992). */
final class VmEnvRuntimeShrinkTest extends TestCase
{
    public function testVmEnvSourceHasNoHostPutenvOrGetenv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmEnv.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\putenv\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\getenv\\s*\\(/', $source);
        $this->assertStringContainsString('VmEnvPutenvNative', $source);
    }

    public function testPutenvNativeHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmEnvPutenvNative.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\FFI/', $source);
        $this->assertStringNotContainsString('libc.so', $source);
        $this->assertStringContainsString('VmEnvEnvironNative::enumerate()', $source);
    }

    public function testPutenvGetenvRoundTripPurePhp(): void
    {
        $key = 'PHP_COMPILER_VMENV_SHRINK_'.getmypid();
        $this->assertTrue(VmEnv::putenv($key.'=roundtrip'));
        $this->assertSame('roundtrip', VmEnv::getenv($key));
        $this->assertSame('roundtrip', VmEnv::getenv($key, true));
        VmEnv::putenv($key);
        $this->assertFalse(VmEnv::getenv($key));
    }

    public function testPutenvNativeAlwaysAvailable(): void
    {
        $this->assertTrue(VmEnvPutenvNative::available());
    }
}
