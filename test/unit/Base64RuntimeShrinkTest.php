<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Base64JitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** base64_encode()/base64_decode() JIT routes through Base64JitHelper PHP (#17234, #17249). */
final class Base64RuntimeShrinkTest extends TestCase
{
    public function testStringBase64EncodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBase64Encode.php');
        $this->assertStringContainsString('Base64JitLink', $source);
        $this->assertStringContainsString('StringBase64EncodeLlvm', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitBase64Encode.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/base64_encode.php');
        $this->assertStringContainsString('StringBase64Encode::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_base64_encode', $builtin);
    }

    public function testStringBase64DecodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBase64Decode.php');
        $this->assertStringContainsString('Base64JitLink', $source);
        $this->assertStringContainsString('StringBase64DecodeLlvm', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitBase64Decode.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/base64_decode.php');
        $this->assertStringContainsString('StringBase64Decode::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_base64_decode', $builtin);
    }

    public function testBase64JitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Base64JitHelper.php');
        $this->assertStringContainsString('VmString::base64_encode', $source);
        $this->assertStringContainsString('VmString::base64_decode', $source);
        $this->assertSame('Zm9v', Base64JitHelper::encodeArgv('foo'));
        $this->assertSame('Zm9v', VmString::base64_encode('foo'));
        $this->assertSame('foo', Base64JitHelper::decodeArgv('Zm9v'));
        $this->assertSame('foo', VmString::base64_decode('Zm9v', false));
    }

    public function testSpineBundleIncludesBase64JitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Base64JitHelper.php', $spine);
        $this->assertStringContainsString('Base64JitLink.php', $spine);
        $this->assertStringContainsString('StringBase64Encode.php', $spine);
        $this->assertStringContainsString('StringBase64Decode.php', $spine);
        $this->assertStringContainsString('JitBase64Encode.php', $spine);
        $this->assertStringContainsString('JitBase64Decode.php', $spine);
    }
}
