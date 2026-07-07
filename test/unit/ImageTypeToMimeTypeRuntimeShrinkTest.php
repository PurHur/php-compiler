<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ImageTypeToMimeTypeJitHelper;
use PHPCompiler\ext\standard\VmImage;
use PHPUnit\Framework\TestCase;

/** image_type_to_mime_type() JIT routes through ImageTypeToMimeTypeJitHelper PHP (#17126). */
final class ImageTypeToMimeTypeRuntimeShrinkTest extends TestCase
{
    public function testJitImageTypeToMimeTypeUsesJitHelperNotInlineLlvm(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitImageTypeToMimeType.php');
        $this->assertStringContainsString('ImageTypeToMimeType::invoke', $jit);
        $this->assertStringNotContainsString('lookupMime', $jit);
        $this->assertStringNotContainsString('MIME_TYPES', $jit);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ImageTypeToMimeType.php');
        $this->assertStringContainsString('ImageTypeToMimeTypeJitHelper', $bridge);
        $this->assertStringContainsString('__phpc_jit_image_type_to_mime_type', $bridge);
        $this->assertStringContainsString('ImageTypeToMimeTypeLlvm', $bridge);
        $this->assertStringContainsString('shouldDefer', $bridge);
    }

    public function testImageTypeToMimeTypeJitHelperDelegatesToVmImage(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ImageTypeToMimeTypeJitHelper.php');
        $this->assertStringContainsString('VmImage::imageTypeToMimeType', $source);

        $this->assertSame(
            VmImage::imageTypeToMimeType(VmImage::IMAGETYPE_PNG),
            ImageTypeToMimeTypeJitHelper::mimeArgv(VmImage::IMAGETYPE_PNG)
        );
        $this->assertSame(
            VmImage::imageTypeToMimeType(999),
            ImageTypeToMimeTypeJitHelper::mimeArgv(999)
        );
    }

    public function testSpineBundleIncludesImageTypeToMimeTypeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ImageTypeToMimeTypeJitHelper.php', $spine);
        $this->assertStringContainsString('ImageTypeToMimeType.php', $spine);
        $this->assertStringContainsString('ImageTypeToMimeTypeLlvm.php', $spine);
        $this->assertStringContainsString('JitImageTypeToMimeType.php', $spine);
    }
}
