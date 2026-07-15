<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StringHashCryptoLlvm digest buffers use arrayType/arrayAlloca — not bare alloca($i8, N) (#19274).
 *
 * PHPLLVM Builder::alloca(Type) ignores extra size args; that made AOT hash() always raw.
 */
final class StringHashCryptoLlvmAllocaShrinkTest extends TestCase
{
    public function testDigestBuffersUseArrayTypeOrArrayAlloca(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashCryptoLlvm.php');
        $this->assertStringContainsString('allocaI8Bytes', $source);
        $this->assertStringContainsString('arrayType', $source);
        $this->assertStringContainsString('arrayAlloca', $source);
        $this->assertStringNotContainsString(
            'alloca($i8, self::MAX_DIGEST_BYTES',
            $source
        );
        $this->assertStringNotContainsString(
            'alloca($i8, $keylenPhi',
            $source
        );
        $this->assertStringContainsString('#19274', $source);
    }
}
