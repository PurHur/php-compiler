<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * v1.1.0 release scope — extensions that must not silently miscompile (#24697).
 *
 * Measured compliance debt clusters in {@code intl} (~89% failing) and {@code gmp}
 * (100% of executed cases). Concentrated debt is shippable when named unsupported;
 * shipping a half-implemented surface yields silent wrong answers — the project's
 * worst-bug category.
 *
 * Product default: withhold {@code extension_loaded()} / {@code function_exists()}
 * for these names unless the operator opts in with {@code PHP_COMPILER_ENABLE_INTL=1}
 * / {@code PHP_COMPILER_ENABLE_GMP=1} (same shape as zip/curl experimental enable).
 *
 * Compliance keeps the cases enabled: {@see applyComplianceEnv()} injects the enable
 * flags for functional PHPT names so the failing set stays visible in the baseline.
 */
final class ReleaseUnsupportedExtensions
{
    public const EXT_INTL = 'intl';

    public const EXT_GMP = 'gmp';

    /**
     * Extensions withheld from the product surface unless explicitly enabled (#24697).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::EXT_INTL, self::EXT_GMP];
    }

    public static function isReleaseUnsupported(string $extension): bool
    {
        return \in_array(strtolower($extension), self::names(), true);
    }

    /**
     * Explicit opt-in — {@code PHP_COMPILER_ENABLE_<EXT>=1} (zip/curl shape).
     */
    public static function explicitEnableRequested(string $extension): bool
    {
        $ext = strtoupper($extension);
        $raw = Config::getenv('PHP_COMPILER_ENABLE_'.$ext);
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * Inject enable flags for functional compliance cases so debt stays measurable (#24697).
     *
     * Phantom / gated cases that assert the surface is withheld must not receive the flag.
     *
     * @param array<string, string> $env
     */
    public static function applyComplianceEnv(string $testFileName, array &$env): void
    {
        if (ext\gmp\GmpExtensionPolicy::isGmpComplianceCase($testFileName)
            && !ext\gmp\GmpExtensionPolicy::isGmpPhantomComplianceCase($testFileName)) {
            $env['PHP_COMPILER_ENABLE_GMP'] = '1';
        }
        if (self::isIntlFunctionalComplianceCase($testFileName)) {
            $env['PHP_COMPILER_ENABLE_INTL'] = '1';
        }
    }

    private static function isIntlFunctionalComplianceCase(string $testFileName): bool
    {
        if (str_contains($testFileName, 'intl_phantom')
            || str_contains($testFileName, 'grapheme_phantom')
            || str_contains($testFileName, 'idn_phantom')
            || str_contains($testFileName, 'normalizer_phantom')
            || str_contains($testFileName, 'locale_gated')
            || str_contains($testFileName, 'extension_loaded_intl_phantom')) {
            return false;
        }

        return str_starts_with($testFileName, 'intl/')
            || str_contains($testFileName, 'grapheme_')
            || str_contains($testFileName, 'idn_to_')
            || str_contains($testFileName, 'idn_enum')
            || str_contains($testFileName, 'normalizer_')
            || str_contains($testFileName, 'locale_')
            || str_contains($testFileName, 'intldateformatter')
            || str_contains($testFileName, 'numberformatter')
            || str_contains($testFileName, 'intlcalendar')
            || str_contains($testFileName, 'msgfmt_')
            || str_contains($testFileName, 'intl_list_formatter')
            || str_contains($testFileName, 'transliterator')
            || str_contains($testFileName, 'resourcebundle')
            || str_contains($testFileName, 'intl_skeleton')
            || str_contains($testFileName, 'intl_char')
            || str_contains($testFileName, 'intl_uconverter')
            || str_contains($testFileName, 'collator_')
            || str_contains($testFileName, 'breakiterator')
            || str_contains($testFileName, 'numfmt_')
            || str_contains($testFileName, 'datefmt_')
            || str_contains($testFileName, 'intlcal_')
            || str_contains($testFileName, 'intltz_');
    }
}
