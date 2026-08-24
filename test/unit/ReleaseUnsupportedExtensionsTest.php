<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group release_unsupported_extensions */
final class ReleaseUnsupportedExtensionsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_ENABLE_GMP');
        putenv('PHP_COMPILER_ENABLE_INTL');
        unset($_ENV['PHP_COMPILER_ENABLE_GMP'], $_ENV['PHP_COMPILER_ENABLE_INTL']);
        parent::tearDown();
    }

    public function testNamesAreIntlAndGmp(): void
    {
        self::assertSame(['intl', 'gmp'], ReleaseUnsupportedExtensions::names());
        self::assertTrue(ReleaseUnsupportedExtensions::isReleaseUnsupported('intl'));
        self::assertTrue(ReleaseUnsupportedExtensions::isReleaseUnsupported('GMP'));
        self::assertFalse(ReleaseUnsupportedExtensions::isReleaseUnsupported('mbstring'));
    }

    public function testGmpWithheldUntilExplicitEnable(): void
    {
        putenv('PHP_COMPILER_ENABLE_GMP');
        unset($_ENV['PHP_COMPILER_ENABLE_GMP']);
        self::assertFalse(ext\gmp\GmpExtensionPolicy::advertisesExtension());

        putenv('PHP_COMPILER_ENABLE_GMP=1');
        $_ENV['PHP_COMPILER_ENABLE_GMP'] = '1';
        self::assertTrue(ext\gmp\GmpExtensionPolicy::advertisesExtension());
    }

    public function testIntlWithheldUntilExplicitEnableEvenIfHostHasIntl(): void
    {
        putenv('PHP_COMPILER_ENABLE_INTL');
        unset($_ENV['PHP_COMPILER_ENABLE_INTL']);
        self::assertFalse(ext\intl\IntlExtensionPolicy::advertisesExtension());

        putenv('PHP_COMPILER_ENABLE_INTL=1');
        $_ENV['PHP_COMPILER_ENABLE_INTL'] = '1';
        // Still requires host php-intl (#22691) once opted in.
        self::assertSame(
            \extension_loaded('intl'),
            ext\intl\IntlExtensionPolicy::advertisesExtension()
        );
    }

    public function testComplianceEnvInjectsEnableForFunctionalCases(): void
    {
        $env = [];
        ReleaseUnsupportedExtensions::applyComplianceEnv('gmp/add_cmp', $env);
        self::assertSame('1', $env['PHP_COMPILER_ENABLE_GMP']);

        $env = [];
        ReleaseUnsupportedExtensions::applyComplianceEnv('stdlib/extension_loaded_gmp_phantom', $env);
        self::assertArrayNotHasKey('PHP_COMPILER_ENABLE_GMP', $env);

        $env = [];
        ReleaseUnsupportedExtensions::applyComplianceEnv('intl/collator_compare_asort', $env);
        self::assertSame('1', $env['PHP_COMPILER_ENABLE_INTL']);

        $env = [];
        ReleaseUnsupportedExtensions::applyComplianceEnv('intl/intl_phantom_module', $env);
        self::assertArrayNotHasKey('PHP_COMPILER_ENABLE_INTL', $env);
    }
}
