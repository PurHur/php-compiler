<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HashCryptoJitHelper;
use PHPCompiler\ext\standard\VmHash;
use PHPUnit\Framework\TestCase;

/** StringHashCryptoPhp routes through HashCryptoJitHelper PHP not LLVM digest monolith (#9164). */
final class StringHashCryptoRuntimeShrinkTest extends TestCase
{
    public function testStringHashCryptoPhpRoutesThroughHashCryptoJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoPhp.php');
        $this->assertStringContainsString('HashCryptoJitHelper', $source);
        $this->assertStringNotContainsString('__phpc_hc_sha256_transform', $source);
        $this->assertStringNotContainsString('emitMd5Transform', $source);
        $this->assertStringNotContainsString('callDigest', $source);
        $this->assertLessThan(260, \substr_count($source, "\n") + 1);
    }

    public function testStringHashCryptoJitUsesPhpBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $this->assertStringContainsString('StringHashCryptoPhp', $source);
        $this->assertStringNotContainsString('StringHashCryptoNativeJit', $source);
    }

    public function testHashCryptoJitHelperDelegatesToVmHash(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HashCryptoJitHelper.php');
        $this->assertStringContainsString('VmHash::hash', $source);
        $this->assertStringContainsString('VmHash::hashHmac', $source);
        $this->assertStringContainsString('VmHash::hashPbkdf2', $source);
        $this->assertStringContainsString('VmHash::hashHkdf', $source);
    }

    public function testHashCryptoJitHelperSemanticsMatchVmHash(): void
    {
        $this->assertSame(
            VmHash::hash('sha256', 'abc'),
            HashCryptoJitHelper::hash('sha256', 'abc', false)
        );
        $this->assertSame(
            VmHash::hashHmac('sha256', 'data', 'key'),
            HashCryptoJitHelper::hashHmac('sha256', 'data', 'key', false)
        );
        $this->assertSame(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            HashCryptoJitHelper::hash('sha256', 'abc', false)
        );
        $this->assertSame(
            '515aae133b435d4000956731f68ae5cf5eb85d4f0dc6a546d2bfcd3595ec1ae1',
            HashCryptoJitHelper::hashHmac('sha256', 'body', 'key', false)
        );
    }
}
