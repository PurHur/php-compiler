<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ConvertUuJitHelper;
use PHPCompiler\ext\standard\VmConvertUu;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * convert_uuencode()/convert_uudecode() JIT routes through ConvertUuJitHelper + VmConvertUu
 * (#13227, #18827, #26898, #30811).
 */
final class ConvertUuRuntimeShrinkTest extends TestCase
{
    public function testStringConvertUuUsesJitHelperBundle(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringConvertUu.php');
        $this->assertStringContainsString('ConvertUuJitHelper', $source);
        $this->assertStringContainsString('VmConvertUu.php', $source);
        $this->assertStringContainsString('HELPER_BUNDLE', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('decodeArgv', $source);
        $this->assertStringContainsString('isHelperResultNull', $source);
        $this->assertStringNotContainsString('decodeTag', $source);
        $this->assertStringNotContainsString('LAST_STRING', $source);
        $this->assertStringNotContainsString('::lastString', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringConvertUuEncodeLlvm', $source);
        $this->assertStringNotContainsString('StringConvertUuDecodeLlvm', $source);
        $this->assertStringNotContainsString('JitConvertUuBodies', $source);
        $this->assertStringNotContainsString('JitConvertUuKernel', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuEncodeLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuDecodeLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitConvertUuBodies.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitConvertUuKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuJit.php');
    }

    public function testUserScriptAotForcesNestedJitOfConvertUuHelper(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\convertuujithelper::encode",
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT encode — prelinked unit.o SIGSEGVs (#30811)'
        );
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\convertuujithelper::decodeargv",
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT decodeArgv — prelinked unit.o SIGSEGVs (#30811)'
        );
    }

    public function testConvertUuJitHelperDelegatesToVmConvertUu(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ConvertUuJitHelper.php');
        $this->assertStringContainsString('VmConvertUu::encode', $source);
        $this->assertStringContainsString('VmConvertUu::decode', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper::warning', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('$data[$', $source);
        $this->assertStringNotContainsString('isset($', $source);
        $this->assertStringNotContainsString('match (', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmConvertUu.php');
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\substr(', $vm);
        $this->assertStringContainsString('\\ord(', $vm);
        $this->assertStringContainsString('\\chr(', $vm);
        $this->assertStringNotContainsString('$src[$', $vm);
        $this->assertStringNotContainsString('isset($', $vm);
    }

    public function testConvertUuJitHelperEncodeRoundTrip(): void
    {
        $encoded = ConvertUuJitHelper::encode('hello');
        $this->assertSame(VmString::convert_uuencode('hello'), $encoded);
        $this->assertSame(VmConvertUu::encode('hello'), $encoded);
        $decoded = ConvertUuJitHelper::decodeArgv($encoded);
        $this->assertSame('hello', $decoded);
        $this->assertSame(VmString::convert_uudecode($encoded), $decoded);
    }

    public function testConvertUuJitHelperCatRoundTripMatchesVmString(): void
    {
        $encoded = ConvertUuJitHelper::encode('cat');
        $this->assertSame(VmString::convert_uuencode('cat'), $encoded);
        $this->assertSame('cat', ConvertUuJitHelper::decodeArgv($encoded));
    }

    public function testConvertUuJitHelperDecodeInvalidReturnsFalse(): void
    {
        $this->assertFalse(ConvertUuJitHelper::decodeArgv('not-uue'));
    }

    public function testSpineBundleIncludesVmConvertUu(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VmConvertUu.php', $spine);
        $this->assertStringContainsString('ConvertUuJitHelper.php', $spine);
        $this->assertStringContainsString('StringConvertUu.php', $spine);
        $this->assertStringNotContainsString('JitConvertUuBodies.php', $spine);
        $this->assertStringNotContainsString('JitConvertUuKernel.php', $spine);
        $this->assertStringNotContainsString('StringConvertUuEncodeLlvm.php', $spine);
        $this->assertStringNotContainsString('StringConvertUuDecodeLlvm.php', $spine);
    }
}
