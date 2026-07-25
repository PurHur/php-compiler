<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gmp\GmpExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group gmp_extension_policy */
final class GmpExtensionPolicyTest extends TestCase
{
    public function testWithholdsOnReferenceWithoutHostGmp(): void
    {
        if (\extension_loaded('gmp') || \PHPCompiler\CompilerVersion::supportsGmp()) {
            $this->markTestSkipped('gmp advertised on this host/profile');
        }

        self::assertFalse(GmpExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('gmp')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'gmp_add')
        );
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'GMP')
        );
    }
}
