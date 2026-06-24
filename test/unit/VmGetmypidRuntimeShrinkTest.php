<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmProcessIdentityNative;
use PHPUnit\Framework\TestCase;

/** getmypid VM must not delegate to host Zend (#8351, pairs #7891). */
final class VmGetmypidRuntimeShrinkTest extends TestCase
{
    public function testVmDateDoesNotCallHostGetmypid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDate.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\getmypid\\s*\\(/', $source);
        $this->assertStringContainsString('VmProcessIdentityNative::getpid()', $source);
    }

    public function testVmProcessIdentityNativeDefinesGetpid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcessIdentityNative.php');
        $this->assertStringContainsString('VmProcessIdentityPure::getpid()', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\getmypid\\s*\\(/', $source);
    }

    public function testGetmypidMatchesLibcGetpidWhenFfiAvailable(): void
    {
        if (!VmProcessIdentityNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $pid = VmProcessIdentityNative::getpid();
        $this->assertNotNull($pid);
        $this->assertGreaterThan(0, $pid);
        $this->assertSame($pid, VmDate::getmypid());
    }
}
