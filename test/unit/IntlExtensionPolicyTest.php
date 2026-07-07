<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group intl_extension_policy */
final class IntlExtensionPolicyTest extends TestCase
{
    public function testGraphemeBuiltinsWithheldUntilIntlLoaded(): void
    {
        self::assertFalse(IntlExtensionPolicy::advertisesBuiltins());
        self::assertFalse(IntlExtensionPolicy::advertisesLocale());
        // locale_get_primary_language/region/script are forward-profile parsers on 8.4.0-dev (#17117).
        self::assertTrue(IntlExtensionPolicy::advertisesLocaleParsers());
        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'grapheme_strlen')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'locale_get_default')
        );
        self::assertTrue(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'locale_get_primary_language')
        );
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'Locale')
        );
    }

    public function testLocaleParsersAdvertisedOnDefault84DevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertTrue(CompilerVersion::supportsLocaleParserForwardProfile());
            self::assertTrue(CompilerVersion::advertisesLocaleParserForwardProfile());
            self::assertTrue(IntlExtensionPolicy::advertisesLocaleParsers());
            self::assertFalse(IntlExtensionPolicy::advertisesLocale());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['locale_get_primary_language', 'locale_get_region', 'locale_get_script'] as $fn) {
                self::assertTrue(isset($ctx->functions[$fn]));
                self::assertTrue(
                    ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must be visible on default 8.4.0-dev profile'
                );
            }
            self::assertFalse(
                ext\standard\VmReflection::functionExists($ctx, 'locale_get_default')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testLocaleParsersAdvertisedOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsLocaleParserForwardProfile());
            self::assertTrue(CompilerVersion::advertisesLocaleParserForwardProfile());
            self::assertTrue(IntlExtensionPolicy::advertisesLocaleParsers());
            self::assertFalse(IntlExtensionPolicy::advertisesLocale());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['locale_get_primary_language', 'locale_get_region', 'locale_get_script'] as $fn) {
                self::assertTrue(isset($ctx->functions[$fn]));
                self::assertTrue(
                    ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must be visible on forward 8.4 profile'
                );
            }
            self::assertFalse(
                ext\standard\VmReflection::functionExists($ctx, 'locale_get_default')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
