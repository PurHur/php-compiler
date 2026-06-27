<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PasswordJitHelper;
use PHPCompiler\ext\standard\VmPassword;
use PHPUnit\Framework\TestCase;

/** StringPasswordCrypto routes through PasswordJitHelper PHP, not libcrypt LLVM (#9908, #12869). */
final class PasswordCryptoRuntimeShrinkTest extends TestCase
{
    public function testStringPasswordCryptoUsesPasswordCryptoRuntimeNotLlvmJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPasswordCrypto.php');
        $this->assertStringContainsString('PasswordCryptoRuntime', $source);
        $this->assertStringNotContainsString('StringPasswordCryptoJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringPasswordCryptoJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringPasswordCryptoStandaloneLlvm.php');
    }

    public function testPasswordCryptoRuntimeRoutesThroughPasswordJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PasswordCryptoRuntime.php');
        $this->assertStringContainsString('PasswordJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('StringPasswordCryptoStandaloneLlvm', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('emitPasswordHash', $source);
        $this->assertStringNotContainsString('BCRYPT_ITOA64', $source);
        $this->assertLessThan(280, \substr_count($source, "\n") + 1);
    }

    public function testPasswordJitHelperDelegatesToVmPassword(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PasswordJitHelper.php');
        $this->assertStringContainsString('VmPassword::hash', $source);
        $this->assertStringContainsString('VmPassword::verify', $source);
        $this->assertStringContainsString('VmPassword::crypt', $source);
        $this->assertStringContainsString('VmPassword::getInfo', $source);
        $this->assertStringContainsString('VmPassword::needsRehash', $source);
        $this->assertStringContainsString('VmPassword::algos', $source);
    }

    public function testPasswordJitHelperHashMatchesVmPassword(): void
    {
        $hash = PasswordJitHelper::hashArgv('secret', VmPassword::PASSWORD_BCRYPT, 4);
        $this->assertIsString($hash);
        $this->assertSame(60, \strlen($hash));
        $this->assertSame(1, PasswordJitHelper::verifyArgv('secret', $hash));
        $this->assertSame(0, PasswordJitHelper::verifyArgv('wrong', $hash));

        $info = PasswordJitHelper::getInfoHashtable($hash);
        $this->assertSame('bcrypt', $info->find('algoName')->resolveIndirect()->toString());
    }

    public function testVmPasswordNativeHasNoHostArgon2Delegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPasswordNative.php');
        $this->assertStringContainsString('argon2Ffi', $source);
        $this->assertStringNotContainsString('hostPasswordHash', $source);
        $this->assertStringNotContainsString('hostPasswordVerify', $source);
        $this->assertStringNotContainsString('\\password_hash(', $source);
        $this->assertStringNotContainsString('\\password_verify(', $source);
    }
}
