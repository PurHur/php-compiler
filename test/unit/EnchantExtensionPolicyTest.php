<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\enchant\EnchantExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** EnchantExtensionPolicy host / ENABLE gate (#23963). */
final class EnchantExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostEnchant(): void
    {
        if (\extension_loaded('enchant')) {
            self::markTestSkipped('host ext/enchant loaded');
        }

        self::assertFalse(EnchantExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('enchant')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'enchant_broker_init')
        );
    }

    public function testProfile84AloneDoesNotInventEnchant(): void
    {
        if (\extension_loaded('enchant')) {
            self::markTestSkipped('host ext/enchant loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_ENCHANT');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_ENCHANT');
        try {
            self::assertFalse(EnchantExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ENCHANT');
            } else {
                putenv('PHP_COMPILER_ENABLE_ENCHANT='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertisesWhenNativeAvailable(): void
    {
        if (\extension_loaded('enchant')) {
            self::markTestSkipped('host ext/enchant loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_ENCHANT');
        putenv('PHP_COMPILER_ENABLE_ENCHANT=1');
        try {
            $available = \PHPCompiler\ext\enchant\VmEnchantNative::available();
            self::assertSame($available, EnchantExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ENCHANT');
            } else {
                putenv('PHP_COMPILER_ENABLE_ENCHANT='.$prevEnable);
            }
        }
    }
}
