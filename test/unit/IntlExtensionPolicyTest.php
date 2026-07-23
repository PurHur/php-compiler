<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group intl_extension_policy */
final class IntlExtensionPolicyTest extends TestCase
{
    public function testUnicodeCoreAdvertisedWhenIcuAvailable(): void
    {
        if (!IntlExtensionPolicy::icuAvailable()) {
            self::assertFalse(IntlExtensionPolicy::advertisesBuiltins());
            self::assertFalse(IntlExtensionPolicy::advertisesIdn());
            self::assertFalse(IntlExtensionPolicy::advertisesNormalizer());
            self::assertFalse(IntlExtensionPolicy::advertisesLocale());
            self::assertFalse(IntlExtensionPolicy::advertisesIntlDateFormatter());

            $runtime = new Runtime();
            self::assertFalse(
                ext\standard\VmReflection::functionExists($runtime->vmContext, 'grapheme_strlen')
            );

            return;
        }

        self::assertTrue(IntlExtensionPolicy::advertisesExtension());
        self::assertTrue(IntlExtensionPolicy::advertisesBuiltins());
        self::assertTrue(IntlExtensionPolicy::advertisesGraphemeCore());
        self::assertTrue(IntlExtensionPolicy::advertisesNormalizer());
        self::assertTrue(IntlExtensionPolicy::advertisesLocale());
        self::assertTrue(IntlExtensionPolicy::advertisesIntlDateFormatter());
        self::assertTrue(IntlExtensionPolicy::advertisesIntlCalendar());
        self::assertTrue(IntlExtensionPolicy::advertisesNumberFormatter());
        self::assertSame(
            IntlExtensionPolicy::advertisesIdn(),
            \PHPCompiler\ext\intl\VmIdn::available()
        );

        $runtime = new Runtime();
        self::assertTrue(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'grapheme_strlen')
        );
        self::assertTrue(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'normalizer_normalize')
        );
        self::assertTrue(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'Normalizer')
        );
        self::assertTrue(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'locale_get_default')
        );
        self::assertTrue(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'Locale')
        );
        self::assertTrue(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'IntlDateFormatter')
        );
        if (IntlExtensionPolicy::advertisesIdn()) {
            self::assertTrue(
                ext\standard\VmReflection::functionExists($runtime->vmContext, 'idn_to_ascii')
            );
            self::assertTrue(
                ext\standard\VmReflection::functionExists($runtime->vmContext, 'idn_to_utf8')
            );
        }
    }

    public function testLocaleParsersWithheldOnDefault84DevProfileWithoutForwardGate(): void
    {
        if (IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('Locale advertises with ICU-backed ext/intl (#20630)');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            // 8.4.0-dev without PHP_COMPILER_PROFILE=8.4 withholds Locale (#19670) and the
            // forward-profile BCP-47 parsers (#17072 / #17117).
            self::assertFalse(IntlExtensionPolicy::advertisesLocale());
            self::assertFalse(IntlExtensionPolicy::advertisesLocaleParsers());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['locale_get_primary_language', 'locale_get_region', 'locale_get_script'] as $fn) {
                self::assertFalse(isset($ctx->functions[$fn]));
                self::assertFalse(
                    ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must stay gated without Locale or forward 8.4 profile'
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

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['locale_get_primary_language', 'locale_get_region', 'locale_get_script'] as $fn) {
                self::assertTrue(isset($ctx->functions[$fn]));
                self::assertTrue(
                    ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must be visible on forward 8.4 profile'
                );
            }
            if (IntlExtensionPolicy::advertisesLocale()) {
                self::assertTrue(
                    ext\standard\VmReflection::functionExists($ctx, 'locale_get_default')
                );
            } else {
                self::assertFalse(
                    ext\standard\VmReflection::functionExists($ctx, 'locale_get_default')
                );
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testGraphemeCoreWithIntlWhenIcuAvailable(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsGraphemeForwardProfileCore());
            if (!IntlExtensionPolicy::icuAvailable()) {
                self::assertFalse(CompilerVersion::advertisesGraphemeForwardProfileCore());
                self::assertFalse(IntlExtensionPolicy::advertisesGraphemeCore());
                self::assertFalse(IntlExtensionPolicy::advertisesBuiltins());

                $runtime = new Runtime();
                $ctx = $runtime->vmContext;
                foreach ([
                    'grapheme_strlen',
                    'grapheme_substr',
                    'grapheme_strpos',
                    'grapheme_extract',
                    'grapheme_str_split',
                    'grapheme_str_contains',
                    'grapheme_strimwidth',
                ] as $fn) {
                    self::assertFalse(isset($ctx->functions[$fn]), $fn.' must not register without ext/intl');
                    self::assertFalse(
                        ext\standard\VmReflection::functionExists($ctx, $fn),
                        $fn.' must not be visible on forward 8.4 profile without intl'
                    );
                }

                return;
            }

            self::assertTrue(IntlExtensionPolicy::advertisesGraphemeCore());
            self::assertTrue(IntlExtensionPolicy::advertisesBuiltins());
            self::assertTrue(IntlExtensionPolicy::advertisesGraphemeStrContains());
            self::assertTrue(IntlExtensionPolicy::advertisesGraphemeStrimwidth());
            self::assertTrue(IntlExtensionPolicy::advertisesGraphemeStrSplit());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach ([
                'grapheme_strlen',
                'grapheme_substr',
                'grapheme_strpos',
                'grapheme_extract',
                'grapheme_str_split',
                'grapheme_str_contains',
                'grapheme_strimwidth',
            ] as $fn) {
                self::assertTrue(isset($ctx->functions[$fn]), $fn.' must register with ICU-backed intl');
                self::assertTrue(
                    ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must be visible when ext/intl advertises (#20630)'
                );
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** PROFILE=8.2: withhold PHP 8.4 grapheme APIs even when ICU advertises intl (#22564). */
    public function testGraphemeStrContainsWithheldOnProfile82WithIntl(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            self::assertFalse(CompilerVersion::supportsGraphemeStrContains());
            self::assertFalse(CompilerVersion::supportsGraphemeStrimwidth());
            self::assertFalse(CompilerVersion::supportsGraphemeStrSplit());
            self::assertFalse(IntlExtensionPolicy::advertisesGraphemeStrContains());
            self::assertFalse(IntlExtensionPolicy::advertisesGraphemeStrimwidth());
            self::assertFalse(IntlExtensionPolicy::advertisesGraphemeStrSplit());

            if (!IntlExtensionPolicy::icuAvailable()) {
                return;
            }

            self::assertTrue(IntlExtensionPolicy::advertisesBuiltins());
            self::assertTrue(IntlExtensionPolicy::advertisesGraphemeCore());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            self::assertTrue(
                ext\standard\VmReflection::functionExists($ctx, 'grapheme_strlen'),
                'grapheme_strlen remains on 8.2 with intl'
            );
            foreach ([
                'grapheme_str_contains',
                'grapheme_strimwidth',
                'grapheme_str_split',
            ] as $fn) {
                self::assertFalse(isset($ctx->functions[$fn]), $fn.' must not register on PROFILE=8.2');
                self::assertFalse(
                    ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must not be visible on PROFILE=8.2 (#22564)'
                );
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
