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
        $expected = ['2y'];
        if (VmPasswordNative::argon2Available()) {
            $expected[] = 'argon2i';
            $expected[] = 'argon2id';
        }
        $this->assertSame($expected, VmPasswordNative::passwordAlgos());
    }

    public function testPasswordHashArgon2WhenHostAvailable(): void
    {
        if (!VmPasswordNative::argon2Available()) {
            $this->markTestSkipped('host PASSWORD_ARGON2ID unavailable');
        }
        $hash = VmPassword::hash('secret', VmPassword::PASSWORD_ARGON2ID);
        $this->assertIsString($hash);
        $this->assertTrue(str_starts_with($hash, '$argon2id$'));
        $this->assertTrue(VmPassword::verify('secret', $hash));
        $info = VmPassword::getInfo($hash);
        $this->assertSame('argon2id', $info['algoName']);
    }

    public function testCryptBcryptSetting(): void
    {
        $hash = VmPassword::crypt('test', '$2y$04$abcdefghijklmnopqrstuu');
        $this->assertNotSame('*0', $hash);
        $this->assertStringStartsWith('$2y$', $hash);
    }

    public function testCryptSha256Salt(): void
    {
        if (!VmPasswordNative::available()) {
            $this->markTestSkipped('libcrypt FFI unavailable');
        }
        $hash = VmPassword::crypt('pass', '$5$rounds=1000$usesomesillystringf');
        $this->assertNotSame('*0', $hash);
        $this->assertStringStartsWith('$5$', $hash);
        $this->assertGreaterThanOrEqual(60, \strlen($hash));
    }
}
