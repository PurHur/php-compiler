<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\stats\StatsExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** StatsExtensionPolicy host / ENABLE gate (#26743). */
final class StatsExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostStats(): void
    {
        if (\extension_loaded('stats')) {
            self::markTestSkipped('host ext/stats loaded');
        }

        self::assertFalse(StatsExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('stats')
        );
        self::assertFalse(
            ext\standard\BuiltinIntrospectionPolicy::functionIsAdvertised('stats_covariance')
        );
        self::assertFalse(
            ext\standard\BuiltinIntrospectionPolicy::functionIsAdvertised('stats_standard_deviation')
        );
        self::assertFalse(
            ext\standard\BuiltinIntrospectionPolicy::functionIsAdvertised('stats_variance')
        );
        unset($runtime);
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('stats')) {
            self::markTestSkipped('host ext/stats loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_STATS');
        putenv('PHP_COMPILER_ENABLE_STATS=1');
        try {
            self::assertTrue(StatsExtensionPolicy::advertisesExtension());
            self::assertTrue(
                ext\standard\BuiltinIntrospectionPolicy::functionIsAdvertised('stats_covariance')
            );
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_STATS');
            } else {
                putenv('PHP_COMPILER_ENABLE_STATS='.$prevEnable);
            }
        }
    }
}
