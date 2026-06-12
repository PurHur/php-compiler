<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmEnv;
use PHPCompiler\ext\standard\VmEnvPutenvNative;
use PHPUnit\Framework\TestCase;

/** VmEnv putenv/getenv without host Zend \\putenv()/\\getenv() (#8086, #5345 phase 2). */
final class VmEnvRuntimeShrinkTest extends TestCase
{
    public function testVmEnvSourceHasNoHostPutenvOrGetenv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmEnv.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\putenv\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\getenv\\s*\\(/', $source);
        $this->assertStringContainsString('VmEnvPutenvNative', $source);
    }

    public function testPutenvNativeUsesLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmEnvPutenvNative.php');
        $this->assertStringContainsString('$ffi->putenv', $source);
        $this->assertStringContainsString('$ffi->getenv', $source);
        $this->assertStringContainsString('copyCString', $source);
    }

    public function testPutenvGetenvRoundTripViaLibc(): void
    {
        if (!VmEnvPutenvNative::available()) {
            $this->markTestSkipped('libc putenv/getenv FFI unavailable on this host');
        }

        $key = 'PHP_COMPILER_VMENV_SHRINK_'.getmypid();
        $this->assertTrue(VmEnv::putenv($key.'=roundtrip'));
        $this->assertSame('roundtrip', VmEnv::getenv($key));
        $this->assertSame('roundtrip', VmEnv::getenv($key, true));
        VmEnv::putenv($key);
        $this->assertFalse(VmEnv::getenv($key));
    }
}
