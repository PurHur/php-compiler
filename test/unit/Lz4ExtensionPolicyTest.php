<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\lz4\Lz4ExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Lz4ExtensionPolicy host / ENABLE gate (#25087). */
final class Lz4ExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostLz4(): void
    {
        if (\extension_loaded('lz4')) {
            self::markTestSkipped('host ext/lz4 loaded');
        }

        self::assertFalse(Lz4ExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('lz4')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'lz4_compress')
        );
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('lz4')) {
            self::markTestSkipped('host ext/lz4 loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_LZ4');
        putenv('PHP_COMPILER_ENABLE_LZ4=1');
        try {
            self::assertTrue(Lz4ExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_LZ4');
            } else {
                putenv('PHP_COMPILER_ENABLE_LZ4='.$prevEnable);
            }
        }
    }
}
