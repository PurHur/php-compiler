<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group intl_extension_policy */
final class IntlExtensionPolicyTest extends TestCase
{
    public function testUnicodeCoreAdvertisedWhenHostIntlLoaded(): void
    {
        if (!\extension_loaded('intl')) {
            self::assertFalse(IntlExtensionPolicy::advertisesExtension());
            self::assertFalse(IntlExtensionPolicy::advertisesBuiltins());
            self::assertFalse(IntlExtensionPolicy::advertisesIdn());
            self::assertFalse(IntlExtensionPolicy::advertisesNormalizer());
            self::assertFalse(IntlExtensionPolicy::advertisesLocale());
            self::assertFalse(IntlExtensionPolicy::advertisesIntlDateFormatter());

            $runtime = new Runtime();
            self::assertFalse(
                ext\standard\VmReflection::functionExists($runtime->vmContext, 'grapheme_strlen')
            );
            self::assertFalse(
                ext\standard\ModuleRegistry::extensionLoaded('intl')
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
            ext\standard\ModuleRegistry::extensionLoaded('intl')
        );
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
            self::markTestSkipped('Locale advertises with host php-intl (#22691)');
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
            if (!\extension_loaded('intl')) {
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
                self::assertTrue(isset($ctx->functions[$fn]), $fn.' must register with host php-intl');
                self::assertTrue(
                    ext\standard\VmReflection::functionExists($ctx, $fn),
                    $fn.' must be visible when host php-intl advertises (#22691)'
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

            if (!\extension_loaded('intl')) {
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

    /** Host INTL_ICU_VERSION major must win FFI candidate order (#22898 / #25081). */
    public function testLibicuucFfiCandidatesPreferHostIntlMajor(): void
    {
        if (!\extension_loaded('intl') || !\defined('INTL_ICU_VERSION')) {
            self::markTestSkipped('host php-intl required');
        }
        $major = IntlExtensionPolicy::hostIntlIcuMajor();
        self::assertGreaterThan(0, $major);
        $candidates = IntlExtensionPolicy::libicuucFfiCandidates();
        self::assertNotEmpty($candidates);
        self::assertSame($major, $candidates[0][2]);
        self::assertSame($major, IntlExtensionPolicy::icuMajorVersion());
    }

    /** ICUDATA-region catalog must match host Zend on the same ICU (#22898). */
    public function testIcudataRegionCatalogMatchesHostZend(): void
    {
        if (!\extension_loaded('intl')) {
            self::markTestSkipped('host php-intl required');
        }
        $script = <<<'PHP'
<?php
$r = ResourceBundle::create('en', 'ICUDATA-region');
$keys = [];
foreach ($r as $k => $_) {
    $keys[] = (string) $k;
}
sort($keys);
$c = $r->get('Countries');
echo count($r), '|', implode(',', $keys), '|', count($c);
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'rb22898_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $script);
        try {
            $zend = [];
            $vm = [];
            $codeZ = 1;
            $codeV = 1;
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp).' 2>/dev/null', $zend, $codeZ);
            $repo = dirname(__DIR__, 2);
            exec(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/bin/vm.php').' '.escapeshellarg($tmp).' 2>/dev/null',
                $vm,
                $codeV
            );
            self::assertSame(0, $codeZ, 'Zend repro failed');
            self::assertSame(0, $codeV, 'VM repro failed');
            self::assertSame(implode("\n", $zend), implode("\n", $vm));
        } finally {
            @unlink($tmp);
        }
    }
}
