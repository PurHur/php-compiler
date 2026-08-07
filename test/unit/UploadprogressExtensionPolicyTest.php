<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\uploadprogress\UploadprogressExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** UploadprogressExtensionPolicy host / ENABLE gate (#26744). */
final class UploadprogressExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostUploadprogress(): void
    {
        if (\extension_loaded('uploadprogress')) {
            self::markTestSkipped('host ext/uploadprogress loaded');
        }

        self::assertFalse(UploadprogressExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('uploadprogress')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'uploadprogress_get_info')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'uploadprogress_get_contents')
        );
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('uploadprogress')) {
            self::markTestSkipped('host ext/uploadprogress loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_UPLOADPROGRESS');
        putenv('PHP_COMPILER_ENABLE_UPLOADPROGRESS=1');
        try {
            self::assertTrue(UploadprogressExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_UPLOADPROGRESS');
            } else {
                putenv('PHP_COMPILER_ENABLE_UPLOADPROGRESS='.$prevEnable);
            }
        }
    }
}
