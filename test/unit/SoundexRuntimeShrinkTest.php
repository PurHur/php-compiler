<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SoundexJitHelper;
use PHPCompiler\ext\standard\VmSoundex;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** soundex() JIT routes through SoundexJitHelper + VmSoundex (#13448, #26882, #30790). */
final class SoundexRuntimeShrinkTest extends TestCase
{
    public function testStringSoundexUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSoundex.php');
        $this->assertStringContainsString('SoundexJitHelper', $source);
        $this->assertStringContainsString('VmSoundex.php', $source);
        $this->assertStringContainsString('HELPER_BUNDLE', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitSoundex.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/soundex.php');
        $this->assertStringContainsString('StringSoundex::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_soundex', $builtin);
        $this->assertStringNotContainsString('JitSoundex', $builtin);
    }

    public function testUserScriptAotForcesNestedJitOfSoundexHelper(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\soundexjithelper::soundexargv",
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT soundexArgv — prelinked unit.o SIGSEGVs (#30790)'
        );
    }

    public function testSoundexJitHelperDelegatesToVmSoundex(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SoundexJitHelper.php');
        $this->assertStringContainsString('VmSoundex::encode', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('$string[$i]', $source);
        $this->assertStringNotContainsString('isset($string[$', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSoundex.php');
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\substr(', $vm);
        $this->assertStringContainsString('advanceIdx', $vm);
        $this->assertStringNotContainsString('$word[$', $vm);
        // NestedJIT multi-arg deep recursion corrupts later builtins (#30790).
        $this->assertStringNotContainsString('encodeFrom', $vm);

        $this->assertSame('E460', SoundexJitHelper::soundexArgv('Euler'));
        $this->assertSame('W252', SoundexJitHelper::soundexArgv('Washington'));
        $this->assertSame('0000', SoundexJitHelper::soundexArgv(''));
        $this->assertSame('0000', SoundexJitHelper::soundexArgv('123'));
        $this->assertSame('E460', VmSoundex::encode('Euler'));
        $this->assertSame(VmString::soundex('Euler'), SoundexJitHelper::soundexArgv('Euler'));
        $this->assertSame(VmString::soundex('Washington'), SoundexJitHelper::soundexArgv('Washington'));
        $this->assertSame(VmString::soundex('Lloyd'), SoundexJitHelper::soundexArgv('Lloyd'));
    }

    public function testSpineBundleIncludesVmSoundex(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitSoundex.php', $spine);
        $this->assertStringContainsString('VmSoundex.php', $spine);
        $this->assertStringContainsString('SoundexJitHelper.php', $spine);
        $this->assertStringContainsString('StringSoundex.php', $spine);
    }
}
