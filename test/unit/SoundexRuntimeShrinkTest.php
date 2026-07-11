<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SoundexJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** soundex() JIT routes through SoundexJitHelper PHP not JitSoundex LLVM (#13448). */
final class SoundexRuntimeShrinkTest extends TestCase
{
    public function testStringSoundexUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSoundex.php');
        $this->assertStringContainsString('SoundexJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitSoundex.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/soundex.php');
        $this->assertStringContainsString('StringSoundex::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_soundex', $builtin);
        $this->assertStringNotContainsString('JitSoundex', $builtin);
    }

    public function testSoundexJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SoundexJitHelper.php');
        $this->assertStringContainsString('VmString::soundex', $source);

        $this->assertSame('W252', SoundexJitHelper::soundexArgv('Washington'));
        $this->assertSame('W252', VmString::soundex('Washington'));
    }

    public function testSpineBundleOmitsDeletedJitSoundex(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitSoundex.php', $spine);
        $this->assertStringContainsString('SoundexJitHelper.php', $spine);
        $this->assertStringContainsString('StringSoundex.php', $spine);
    }
}
