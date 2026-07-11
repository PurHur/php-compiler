<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ImageTypeToMimeTypeJitHelper;
use PHPCompiler\ext\standard\VmImage;
use PHPUnit\Framework\TestCase;

/** image_type_to_mime_type() JIT routes through ImageTypeToMimeTypeJitHelper PHP not inline LLVM (#17126). */
final class ImageTypeToMimeTypeRuntimeShrinkTest extends TestCase
{
    public function testJitImageTypeToMimeTypeUsesJitHelperNotInlineLlvm(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitImageTypeToMimeType.php');
        $this->assertStringContainsString('ImageTypeToMimeType::invoke', $jit);
        $this->assertStringNotContainsString('lookupMime', $jit);
        $this->assertStringNotContainsString('MIME_TYPES', $jit);
        $this->assertLessThan(50, substr_count($jit, "\n") + 1);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ImageTypeToMimeType.php');
        $this->assertStringContainsString('ImageTypeToMimeTypeJitHelper', $bridge);
        $this->assertStringContainsString('__phpc_jit_image_type_to_mime_type', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ImageTypeToMimeTypeLlvm.php');
    }

    public function testImageTypeToMimeTypeJitHelperIsSwitchBasedSsot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ImageTypeToMimeTypeJitHelper.php');
        $this->assertStringContainsString('switch ($imageType)', $source);

        $this->assertSame('image/png', ImageTypeToMimeTypeJitHelper::mimeArgv(VmImage::IMAGETYPE_PNG));
        $this->assertSame('image/jpeg', ImageTypeToMimeTypeJitHelper::mimeArgv(VmImage::IMAGETYPE_JPEG));
        $this->assertSame('application/octet-stream', ImageTypeToMimeTypeJitHelper::mimeArgv(999));
        $this->assertSame('application/octet-stream', VmImage::imageTypeToMimeType(999));
    }

    public function testSpineBundleIncludesImageTypeToMimeTypeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ImageTypeToMimeTypeJitHelper.php', $spine);
        $this->assertStringContainsString('ImageTypeToMimeType.php', $spine);
        $this->assertStringContainsString('JitImageTypeToMimeType.php', $spine);
        $this->assertStringNotContainsString('ImageTypeToMimeTypeLlvm.php', $spine);
        $this->assertStringNotContainsString('StringImageTypeToMimeType.php', $spine);
    }
}
