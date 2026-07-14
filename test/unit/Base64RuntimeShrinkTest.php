<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Base64JitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** base64_encode() JIT routes through Base64JitHelper PHP (#17234, #18918 phase 1). */
final class Base64RuntimeShrinkTest extends TestCase
{
    public function testStringBase64EncodeUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBase64Encode.php');
        $this->assertStringContainsString('Base64JitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringBase64EncodeLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringBase64EncodeLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitBase64Encode.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/base64_encode.php');
        $this->assertStringContainsString('StringBase64Encode::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_base64_encode', $builtin);
        $this->assertStringNotContainsString('JitBase64Encode', $builtin);
    }

    public function testBase64JitHelperEncodeDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Base64JitHelper.php');
        $this->assertStringContainsString('VmString::base64_encode', $source);
        $this->assertSame('Zm9v', Base64JitHelper::encodeArgv('foo'));
        $this->assertSame('Zm9v', VmString::base64_encode('foo'));
    }

    public function testSpineBundleOmitsDeletedBase64EncodeLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Base64JitHelper.php', $spine);
        $this->assertStringContainsString('StringBase64Encode.php', $spine);
        $this->assertStringNotContainsString('JitBase64Encode.php', $spine);
        $this->assertStringNotContainsString('StringBase64EncodeLlvm.php', $spine);
    }
}
