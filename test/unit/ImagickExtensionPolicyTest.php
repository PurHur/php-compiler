<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\imagick\ImagickExtensionPolicy;
use PHPCompiler\ext\imagick\VmImagickNative;
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
        if (VmImagickNative::cliAvailable()) {
            $this->markTestSkipped('ImageMagick CLI present without explicit enable');
        }

        $this->assertFalse(ImagickExtensionPolicy::advertisesExtension());
    }

    public function testExplicitEnableWithCli(): void
    {
        if (\extension_loaded('imagick')) {
            $this->markTestSkipped('host pecl-imagick present');
        }
        if (!VmImagickNative::cliAvailable()) {
            $this->markTestSkipped('ImageMagick CLI not installed');
        }

        putenv('PHP_COMPILER_ENABLE_IMAGICK=1');
        $this->assertTrue(ImagickExtensionPolicy::advertisesExtension());
    }
}
