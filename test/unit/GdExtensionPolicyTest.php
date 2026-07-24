<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gd\GdExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group gd_extension_policy */
final class GdExtensionPolicyTest extends TestCase
{
    public function testAdvertisesWithHostPhpGd(): void
    {
        if (!\extension_loaded('gd')) {
            self::assertFalse(GdExtensionPolicy::advertisesExtension());
            self::assertFalse(GdExtensionPolicy::advertisesDecodeFromString());
            self::assertFalse(GdExtensionPolicy::advertisesDrawing());

            $runtime = new Runtime();
            self::assertFalse(
                ext\standard\ModuleRegistry::extensionLoaded('gd')
            );
            self::assertFalse(
                ext\standard\VmReflection::functionExists($runtime->vmContext, 'gd_info')
            );
            self::assertFalse(
                ext\standard\VmReflection::functionExists($runtime->vmContext, 'imagecreate')
            );
            self::assertFalse(
                ext\standard\VmReflection::classExists($runtime->vmContext, 'GdImage')
            );

            return;
        }

        self::assertTrue(GdExtensionPolicy::advertisesExtension());
        self::assertTrue(GdExtensionPolicy::advertisesDecodeFromString());
        self::assertTrue(GdExtensionPolicy::advertisesDrawing());

        $runtime = new Runtime();
        self::assertTrue(
            ext\standard\ModuleRegistry::extensionLoaded('gd')
        );
        self::assertTrue(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'gd_info')
        );
        self::assertTrue(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'imagecreate')
        );
        self::assertTrue(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'GdImage')
        );
    }

    public function testRunsGdComplianceAllowsPhantomWhenWithheld(): void
    {
        if (GdExtensionPolicy::advertisesExtension()) {
            self::assertTrue(GdExtensionPolicy::runsGdCompliance('gd_imagecreate.phpt'));
            self::assertFalse(GdExtensionPolicy::runsGdCompliance('extension_loaded_gd_phantom.phpt'));

            return;
        }

        self::assertFalse(GdExtensionPolicy::runsGdCompliance('gd_imagecreate.phpt'));
        self::assertTrue(GdExtensionPolicy::runsGdCompliance('extension_loaded_gd_phantom.phpt'));
        self::assertTrue(GdExtensionPolicy::runsGdCompliance('gd_phantom_guard.phpt'));
    }
}
