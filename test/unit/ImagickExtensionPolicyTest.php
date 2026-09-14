<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\imagick\ImagickExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** #6235 — ext/imagick advertisement policy. */
final class ImagickExtensionPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_ENABLE_IMAGICK');
        parent::tearDown();
    }

    public function testWithheldWithoutHostOrEnable(): void
    {
        putenv('PHP_COMPILER_ENABLE_IMAGICK');
        if (\extension_loaded('imagick')) {
            $this->markTestSkipped('host pecl-imagick present');
        }

        $this->assertFalse(ImagickExtensionPolicy::advertisesExtension());
    }

    public function testExplicitEnableAdvertisesImagick(): void
    {
        if (\extension_loaded('imagick')) {
            $this->markTestSkipped('host pecl-imagick present');
        }

        putenv('PHP_COMPILER_ENABLE_IMAGICK=1');
        $this->assertTrue(ImagickExtensionPolicy::advertisesExtension());
    }
}
