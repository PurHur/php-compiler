<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SoundexJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** soundex() JIT routes through SoundexJitHelper PHP not JitSoundex LLVM (#13448, #26882). */
final class SoundexRuntimeShrinkTest extends TestCase
{
    public function testStringSoundexUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSoundex.php');
        $this->assertStringContainsString('SoundexJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('HELPER_BUNDLE', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitSoundex.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/soundex.php');
        $this->assertStringContainsString('StringSoundex::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_soundex', $builtin);
        $this->assertStringNotContainsString('JitSoundex', $builtin);
    }

    public function testSoundexJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SoundexJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('VmSoundex::', $source);
        $this->assertStringContainsString('soundexDigitChar', $source);
        $this->assertStringContainsString('toUpperAlpha', $source);

        $this->assertSame('E460', SoundexJitHelper::soundexArgv('Euler'));
        $this->assertSame('W252', SoundexJitHelper::soundexArgv('Washington'));
        $this->assertSame('0000', SoundexJitHelper::soundexArgv(''));
        $this->assertSame('0000', SoundexJitHelper::soundexArgv('123'));
        $this->assertSame(VmString::soundex('Euler'), SoundexJitHelper::soundexArgv('Euler'));
        $this->assertSame(VmString::soundex('Washington'), SoundexJitHelper::soundexArgv('Washington'));
        $this->assertSame(VmString::soundex('Lloyd'), SoundexJitHelper::soundexArgv('Lloyd'));
    }

    public function testSpineBundleOmitsDeletedJitSoundex(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitSoundex.php', $spine);
        $this->assertStringNotContainsString('VmSoundex.php', $spine);
        $this->assertStringContainsString('SoundexJitHelper.php', $spine);
        $this->assertStringContainsString('StringSoundex.php', $spine);
    }
}
