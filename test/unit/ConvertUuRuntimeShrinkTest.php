<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ConvertUuJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** convert_uuencode()/convert_uudecode() JIT routes through ConvertUuJitHelper PHP (#13227, #18827, #26898). */
final class ConvertUuRuntimeShrinkTest extends TestCase
{
    public function testStringConvertUuUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringConvertUu.php');
        $this->assertStringContainsString('ConvertUuJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('decodeArgv', $source);
        $this->assertStringContainsString('isHelperResultNull', $source);
        $this->assertStringNotContainsString('decodeTag', $source);
        $this->assertStringNotContainsString('LAST_STRING', $source);
        $this->assertStringNotContainsString('::lastString', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringConvertUuEncodeLlvm', $source);
        $this->assertStringNotContainsString('StringConvertUuDecodeLlvm', $source);
        $this->assertStringNotContainsString('JitConvertUuBodies', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuEncodeLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuDecodeLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitConvertUuBodies.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringConvertUuJit.php');
    }

    public function testConvertUuJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ConvertUuJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringContainsString('decodeArgv', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper::warning', $source);
        $this->assertStringNotContainsString('$lastString', $source);
        $this->assertStringNotContainsString('function decodeTag', $source);
        $this->assertStringNotContainsString('function lastString', $source);
        $this->assertStringNotContainsString('\\ord(', $source);
        $this->assertStringNotContainsString('\\chr(', $source);
        $this->assertStringNotContainsString('strlen(', $source);
    }

    public function testConvertUuJitHelperEncodeRoundTrip(): void
    {
        $encoded = ConvertUuJitHelper::encode('hello');
        $this->assertSame(VmString::convert_uuencode('hello'), $encoded);
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

    public function testSpineBundleOmitsDeletedConvertUuLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitConvertUuBodies.php', $spine);
        $this->assertStringNotContainsString('StringConvertUuEncodeLlvm.php', $spine);
        $this->assertStringNotContainsString('StringConvertUuDecodeLlvm.php', $spine);
        $this->assertStringContainsString('ConvertUuJitHelper.php', $spine);
        $this->assertStringContainsString('StringConvertUu.php', $spine);
    }
}
