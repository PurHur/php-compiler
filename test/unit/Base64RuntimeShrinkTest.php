<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Base64DecodeJitHelper;
use PHPCompiler\ext\standard\Base64EncodeJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** base64_encode()/base64_decode() JIT routes through Base64*JitHelper PHP not inline LLVM (#17234). */
final class Base64RuntimeShrinkTest extends TestCase
{
    public function testStringBase64EncodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBase64Encode.php');
        $this->assertStringContainsString('Base64EncodeJitHelper', $source);
        $this->assertStringContainsString('StringBase64EncodeLlvm', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitBase64Encode.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/base64_encode.php');
        $this->assertStringContainsString('StringBase64Encode::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_base64_encode', $builtin);
    }

    public function testStringBase64DecodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBase64Decode.php');
        $this->assertStringContainsString('Base64DecodeJitHelper', $source);
        $this->assertStringContainsString('StringBase64DecodeLlvm', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitBase64Decode.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/base64_decode.php');
        $this->assertStringContainsString('StringBase64Decode::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_base64_decode', $builtin);
    }

    public function testBase64JitHelpersDelegateToVmString(): void
    {
        $encodeHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/Base64EncodeJitHelper.php');
        $this->assertStringContainsString('VmString::base64_encode', $encodeHelper);
        $this->assertSame('Zm9v', Base64EncodeJitHelper::encodeArgv('foo'));
        $this->assertSame('Zm9v', VmString::base64_encode('foo'));

        $decodeHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/Base64DecodeJitHelper.php');
        $this->assertStringContainsString('VmString::base64_decode', $decodeHelper);
        $this->assertSame('foo', Base64DecodeJitHelper::decodeArgv('Zm9v'));
        $this->assertSame('foo', VmString::base64_decode('Zm9v', false));
    }

    public function testSpineBundleIncludesBase64JitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Base64EncodeJitHelper.php', $spine);
        $this->assertStringContainsString('Base64DecodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringBase64Encode.php', $spine);
        $this->assertStringContainsString('StringBase64Decode.php', $spine);
        $this->assertStringContainsString('JitBase64Encode.php', $spine);
        $this->assertStringContainsString('JitBase64Decode.php', $spine);
    }
}
