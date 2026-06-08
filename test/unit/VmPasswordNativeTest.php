<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmPassword;
use PHPCompiler\ext\standard\VmPasswordNative;
use PHPUnit\Framework\TestCase;

/**
 * @group vm
 */
final class VmPasswordNativeTest extends TestCase
{
    public function testLibcryptAvailableInDockerHarness(): void
    {
        $this->assertTrue(
            VmPasswordNative::available(),
            'libcrypt FFI required for native password builtins (#4794)'
        );
    }

    public function testPasswordHashAndVerifyRoundTrip(): void
    {
        $hash = VmPassword::hash('secret', VmPassword::PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertIsString($hash);
        $this->assertTrue(str_starts_with($hash, '$2y$'));
        $this->assertTrue(VmPassword::verify('secret', $hash));
        $this->assertFalse(VmPassword::verify('wrong', $hash));
    }

    public function testPasswordAlgosNativeList(): void
    {
        $this->assertSame(['2y'], VmPasswordNative::passwordAlgos());
    }

    public function testCryptBcryptSetting(): void
    {
        $hash = VmPassword::crypt('test', '$2y$04$abcdefghijklmnopqrstuu');
        $this->assertNotSame('*0', $hash);
        $this->assertStringStartsWith('$2y$', $hash);
    }
}
