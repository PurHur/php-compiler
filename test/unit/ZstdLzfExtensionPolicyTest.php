<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\lzf\LzfExtensionPolicy;
use PHPCompiler\ext\zstd\ZstdExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Zstd/LzfExtensionPolicy host / ENABLE gate (#25287). */
final class ZstdLzfExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostPecl(): void
    {
        if (\extension_loaded('zstd') || \extension_loaded('lzf')) {
            self::markTestSkipped('host zstd/lzf loaded');
        }

        self::assertFalse(ZstdExtensionPolicy::advertisesExtension());
        self::assertFalse(LzfExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('zstd')
        );
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('lzf')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'zstd_compress')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'lzf_compress')
        );
    }

    public function testExplicitEnableAdvertisesZstd(): void
    {
        if (\extension_loaded('zstd')) {
            self::markTestSkipped('host ext/zstd loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_ZSTD');
        putenv('PHP_COMPILER_ENABLE_ZSTD=1');
        try {
            self::assertTrue(ZstdExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ZSTD');
            } else {
                putenv('PHP_COMPILER_ENABLE_ZSTD='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertisesLzf(): void
    {
        if (\extension_loaded('lzf')) {
            self::markTestSkipped('host ext/lzf loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_LZF');
        putenv('PHP_COMPILER_ENABLE_LZF=1');
        try {
            self::assertTrue(LzfExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_LZF');
            } else {
                putenv('PHP_COMPILER_ENABLE_LZF='.$prevEnable);
            }
        }
    }
}
