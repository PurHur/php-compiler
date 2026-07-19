<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Digest buffers use arrayType/arrayAlloca in JitHashCryptoKernel EVP leaves (#19274, #21026). */
final class StringHashCryptoLlvmAllocaShrinkTest extends TestCase
{
    public function testDigestBuffersUseArrayTypeOrArrayAlloca(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/hash/JitHashCryptoKernel.php');
        $this->assertStringContainsString('allocaI8Bytes', $source);
        $this->assertStringContainsString('arrayType', $source);
        $this->assertStringContainsString('__phpc_hc_evp_hash', $source);
        $this->assertStringNotContainsString('alloca($i8, self::MAX_DIGEST_BYTES', $source);
    }
}
