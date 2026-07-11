<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ImageTypeToExtensionJitHelper;
use PHPCompiler\ext\standard\VmImage;
use PHPUnit\Framework\TestCase;

/** image_type_to_extension() JIT routes through ImageTypeToExtensionJitHelper PHP not inline LLVM (#14851). */
final class ImageTypeToExtensionRuntimeShrinkTest extends TestCase
{
    public function testStringImageTypeToExtensionUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringImageTypeToExtension.php');
        $this->assertStringContainsString('ImageTypeToExtensionJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitImageTypeToExtension.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/image_type_to_extension.php');
        $this->assertStringContainsString('StringImageTypeToExtension::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_image_type_to_extension', $builtin);
        $this->assertStringNotContainsString('JitImageTypeToExtension', $builtin);
    }

    public function testImageTypeToExtensionJitHelperDelegatesToVmImage(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ImageTypeToExtensionJitHelper.php');
        $this->assertStringContainsString('VmImage::imageTypeToExtension', $source);

        $this->assertSame(
            ImageTypeToExtensionJitHelper::TAG_STRING,
            ImageTypeToExtensionJitHelper::lookupArgv(VmImage::IMAGETYPE_PNG, true)
        );
        $this->assertSame('.png', ImageTypeToExtensionJitHelper::lastString());
        $this->assertSame('png', VmImage::imageTypeToExtension(VmImage::IMAGETYPE_PNG, false));

        $this->assertSame(
            ImageTypeToExtensionJitHelper::TAG_FALSE,
            ImageTypeToExtensionJitHelper::lookupArgv(99999, true)
        );
        $this->assertFalse(VmImage::imageTypeToExtension(99999, true));
    }

    public function testSpineBundleIncludesImageTypeToExtensionJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitImageTypeToExtension.php', $spine);
        $this->assertStringContainsString('ImageTypeToExtensionJitHelper.php', $spine);
        $this->assertStringContainsString('StringImageTypeToExtension.php', $spine);
        $this->assertStringContainsString('JitImageTypeArg.php', $spine);
    }
}
