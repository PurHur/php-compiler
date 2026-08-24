<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gmp\GmpExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group gmp_extension_policy */
final class GmpExtensionPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_ENABLE_GMP');
        unset($_ENV['PHP_COMPILER_ENABLE_GMP']);
        parent::tearDown();
    }

    public function testWithholdsOnReferenceWithoutHostGmp(): void
    {
        putenv('PHP_COMPILER_ENABLE_GMP');
        unset($_ENV['PHP_COMPILER_ENABLE_GMP']);
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

    public function testReleaseScopeRequiresExplicitEnable(): void
    {
        putenv('PHP_COMPILER_ENABLE_GMP');
        unset($_ENV['PHP_COMPILER_ENABLE_GMP']);
        self::assertFalse(GmpExtensionPolicy::advertisesExtension());

        putenv('PHP_COMPILER_ENABLE_GMP=1');
        $_ENV['PHP_COMPILER_ENABLE_GMP'] = '1';
        self::assertTrue(GmpExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertTrue(
            ext\standard\ModuleRegistry::extensionLoaded('gmp')
        );
        self::assertTrue(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'gmp_add')
        );
    }
}
