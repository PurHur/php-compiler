<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ImageTypeToMimeTypeJitHelper;
use PHPCompiler\ext\standard\VmImage;
use PHPUnit\Framework\TestCase;

/** image_type_to_mime_type() JIT routes through ImageTypeToMimeTypeJitHelper PHP not inline LLVM (#17126). */
final class ImageTypeToMimeTypeRuntimeShrinkTest extends TestCase
{
    public function testStringImageTypeToMimeTypeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringImageTypeToMimeType.php');
        $this->assertStringContainsString('ImageTypeToMimeTypeJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitImageTypeToMimeType.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/image_type_to_mime_type.php');
        $this->assertStringContainsString('StringImageTypeToMimeType::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_image_type_to_mime_type', $builtin);
        $this->assertStringNotContainsString('JitImageTypeToMimeType', $builtin);
    }

    public function testImageTypeToMimeTypeJitHelperDelegatesToVmImage(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ImageTypeToMimeTypeJitHelper.php');
        $this->assertStringContainsString('switch ($imageType)', $source);

        $this->assertSame('image/png', ImageTypeToMimeTypeJitHelper::lookupArgv(VmImage::IMAGETYPE_PNG));
        $this->assertSame('image/jpeg', ImageTypeToMimeTypeJitHelper::lookupArgv(VmImage::IMAGETYPE_JPEG));
        $this->assertSame('application/octet-stream', ImageTypeToMimeTypeJitHelper::lookupArgv(999));
        $this->assertSame('application/octet-stream', VmImage::imageTypeToMimeType(999));
    }

    public function testSpineBundleIncludesImageTypeToMimeTypeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitImageTypeToMimeType.php', $spine);
        $this->assertStringContainsString('ImageTypeToMimeTypeJitHelper.php', $spine);
        $this->assertStringContainsString('StringImageTypeToMimeType.php', $spine);
        $this->assertStringContainsString('JitImageTypeArg.php', $spine);
    }
}
