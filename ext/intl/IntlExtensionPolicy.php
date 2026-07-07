<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/intl builtin advertisement — php-src ext/intl/php_intl.c module registration (#11768, #11825).
 *
 * Grapheme helpers and intl_* functions require a loaded intl extension on Zend; partial
 * PHP implementations stay compiled in-tree but are withheld from function_exists() and
 * intl OOP class_exists() until {@see ModuleRegistry::extensionLoaded}('intl') is true
 * (full ext/intl parity, #11472).
 */
final class IntlExtensionPolicy
{
    /** locale_get_default()/Locale — php-src registers only with loaded ext/intl (#9576, #16214). */
    public static function advertisesLocale(): bool
    {
        return ModuleRegistry::extensionLoaded('intl');
    }

    /**
     * locale_get_primary_language/region/script — full ext/intl or forward 8.4 profile (#5125, #17072).
     */
    public static function advertisesLocaleParsers(): bool
    {
        return self::advertisesLocale()
            || CompilerVersion::advertisesLocaleParserForwardProfile();
    }

    /** grapheme_* / intl_get_error_* — withheld until full ext/intl (#11472, #5156). */
    public static function advertisesBuiltins(): bool
    {
        return ModuleRegistry::extensionLoaded('intl');
    }

    /**
     * PHP 8.4 profile grapheme builtins registered without full ext/intl (#16667, #7128, #9793).
     *
     * @return list<\PHPCompiler\Internal>
     */
    public static function profileGraphemeFunctions(): array
    {
        $functions = [];
        if (CompilerVersion::supportsGraphemeForwardProfileCore()) {
            $functions[] = new grapheme_strlen();
            $functions[] = new grapheme_substr();
            $functions[] = new grapheme_strpos();
            $functions[] = new grapheme_extract();
            $functions[] = new grapheme_str_split();
        }
        if (CompilerVersion::supportsGraphemeStrContains()) {
            $functions[] = new grapheme_str_contains();
        }
        if (CompilerVersion::supportsGraphemeStrimwidth()) {
            $functions[] = new grapheme_strimwidth();
        }

        return $functions;
    }

    /**
     * PHP 8.4 profile locale BCP-47 parsers registered without full ext/intl (#17072, #5125).
     *
     * @return list<\PHPCompiler\Internal>
     */
    public static function profileLocaleParserFunctions(): array
    {
        if (!CompilerVersion::supportsLocaleParserForwardProfile()) {
            return [];
        }

        return [
            new locale_get_primary_language(),
            new locale_get_region(),
            new locale_get_script(),
        ];
    }

    /** Run locale parser compliance when ext/intl is loaded or forward 8.4 profile matches (#17072). */
    public static function runsLocaleParserCompliance(string $testFileName): bool
    {
        if (self::advertisesLocale()) {
            return true;
        }
        if (!CompilerVersion::supportsLocaleParserForwardProfile()) {
            return false;
        }
        if (str_contains($testFileName, 'locale_get_parts')
            || str_contains($testFileName, 'locale_get_primary_language')) {
            return true;
        }

        return false;
    }

    /** Run grapheme compliance when ext/intl is loaded or a profile gate matches (#16667). */
    public static function runsGraphemeCompliance(string $testFileName): bool
    {
        if (self::advertisesBuiltins()) {
            return true;
        }
        if (str_contains($testFileName, 'grapheme_phantom')
            || str_contains($testFileName, 'grapheme_stripos_intl_gated')
            || str_contains($testFileName, 'grapheme_forward_profile')
            || str_contains($testFileName, 'grapheme_profile_84')) {
            return true;
        }
        if (str_contains($testFileName, 'grapheme_str_contains')
            && CompilerVersion::supportsGraphemeStrContains()) {
            return true;
        }
        if (str_contains($testFileName, 'grapheme_strimwidth')
            && CompilerVersion::supportsGraphemeStrimwidth()) {
            return true;
        }
        if (CompilerVersion::supportsGraphemeForwardProfileCore()) {
            foreach ([
                'grapheme_strlen',
                'grapheme_substr',
                'grapheme_strpos',
                'grapheme_extract',
                'grapheme_str_split',
            ] as $basename) {
                if (str_contains($testFileName, $basename)) {
                    return true;
                }
            }
        }

        return false;
    }
}
