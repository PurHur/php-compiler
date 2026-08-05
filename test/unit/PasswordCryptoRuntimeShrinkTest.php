<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PasswordJitHelper;
use PHPCompiler\ext\standard\VmPassword;
use PHPCompiler\ext\standard\VmPasswordNative;
use PHPUnit\Framework\TestCase;

/**
 * StringPasswordCrypto / PasswordCryptoRuntime via PasswordJitHelper PHP (#9908, #12869, #22934).
 * NestedJIT helper compile: JitVmHelperLink::ensureCompiled (peer Libcrypt #22886).
 */
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

    public function testPasswordCryptoRuntimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PasswordCryptoRuntime.php');
        $this->assertStringContainsString('PasswordJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringPasswordCryptoStandaloneLlvm', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('emitPasswordHash', $source);
        $this->assertStringNotContainsString('BCRYPT_ITOA64', $source);
        $this->assertLessThan(280, \substr_count($source, "\n") + 1);
    }

    public function testPasswordJitHelperDelegatesToVmPassword(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PasswordJitHelper.php');
        // Host/VM path still uses VmPassword; NestedJIT uses thin kernels (#26773).
        $this->assertStringContainsString('VmPassword::hash', $source);
        $this->assertStringContainsString('VmPassword::verify', $source);
        $this->assertStringContainsString('VmPassword::crypt', $source);
        $this->assertStringContainsString('VmPassword::getInfo', $source);
        $this->assertStringContainsString('VmPassword::needsRehash', $source);
        // NestedJIT/AOT: algosArgv() literal list (HashTable return emptied under AOT — #27658).
        $this->assertStringContainsString('algosArgv', $source);
        $this->assertStringContainsString("['2y', 'argon2i', 'argon2id']", $source);
        $this->assertStringContainsString('VmPasswordNative::passwordAlgos', $source);
        $this->assertStringContainsString('phpc_argon2_hash', $source);
        $this->assertStringContainsString('phpc_libcrypt_kernel', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        // NestedJIT: strlen($out) after .= never grows — infinite loop / SEGV (#26861).
        $this->assertStringContainsString('while ($n < 22)', $source);
        $this->assertMatchesRegularExpression('/while\s*\(\s*\$n\s*<\s*22\s*\)/', $source);
        $this->assertDoesNotMatchRegularExpression('/while\s*\(\s*\\\\?strlen\s*\(\s*\$out\s*\)/', $source);
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

        $this->assertSame(VmPasswordNative::passwordAlgos(), PasswordJitHelper::algosArgv());

        if (VmPasswordNative::argon2Available()) {
            $argon = PasswordJitHelper::hashArgv('secret', VmPassword::PASSWORD_ARGON2ID, 0);
            $this->assertIsString($argon);
            $this->assertStringStartsWith('$argon2id$', $argon);
            $this->assertSame(1, PasswordJitHelper::verifyArgv('secret', $argon));
        }
    }

    public function testVmPasswordNativeHasNoHostArgon2Delegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPasswordNative.php');
        $this->assertStringContainsString('argon2Ffi', $source);
        $this->assertStringContainsString('VmPasswordPure::', $source);
        $this->assertStringNotContainsString('libcrypt.so', $source);
        $this->assertStringNotContainsString('hostPasswordHash', $source);
        $this->assertStringNotContainsString('hostPasswordVerify', $source);
        $this->assertStringNotContainsString('\\password_hash(', $source);
        $this->assertStringNotContainsString('\\password_verify(', $source);
    }

    public function testVmPasswordPureHasNoFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPasswordPure.php');
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testStringPasswordCryptoHasNoLibcryptDlopen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPasswordCrypto.php');
        $this->assertStringNotContainsString('preloadLibcrypt', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('dlopen', $source);
    }
}
