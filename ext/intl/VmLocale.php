<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Process default BCP-47 locale id (php-src ext/intl/php_intl.c; issue #9576).
 *
 * v1: PHP-only store without ICU — uloc wiring deferred to full ext/intl (#11472).
 */
final class VmLocale
{
    private static ?string $default = null;

    public static function getDefault(): string
    {
        if (null === self::$default) {
            self::$default = self::detectSystemDefault();
        }

        return self::$default;
    }

    public static function setDefault(string $locale): bool
    {
        self::assertValidLocaleId($locale);
        self::$default = $locale;

        return true;
    }

    public static function resetDefaultForTests(): void
    {
        self::$default = null;
    }

    /**
     * locale_get_primary_language() — first BCP-47 subtag (php-src ext/intl/locale/locale_methods.c).
     */
    public static function getPrimaryLanguage(string $locale): string
    {
        $id = self::resolveLocaleOperand($locale);

        return self::parseBcp47Tags($id)['language'];
    }

    /**
     * locale_get_region() — region subtag when present (php-src ext/intl/locale/locale_methods.c).
     */
    public static function getRegion(string $locale): string
    {
        $id = self::resolveLocaleOperand($locale);

        return self::parseBcp47Tags($id)['region'];
    }

    /**
     * locale_get_script() — 4-letter script subtag when present (php-src ext/intl/locale/locale_methods.c).
     */
    public static function getScript(string $locale): string
    {
        $id = self::resolveLocaleOperand($locale);

        return self::parseBcp47Tags($id)['script'];
    }

    /**
     * Locale::getDisplayName() — English display label without ICU (#6696).
     *
     * php-src uses ICU uloc_getDisplayName(); v1 returns a deterministic English approximation
     * so bootstrap/i18n callers get a non-empty string for common language/region tags.
     *
     * @return string|false
     */
    public static function getDisplayName(string $locale, ?string $displayLocale = null): string|false
    {
        unset($displayLocale); // ICU display-locale selection deferred with full ext/intl (#11472).
        $id = self::resolveLocaleOperand($locale);
        $tags = self::parseBcp47Tags($id);
        if ('' === $tags['language']) {
            return false;
        }
        $language = self::englishLanguageName($tags['language']);
        if (null === $language) {
            return false;
        }
        if ('' !== $tags['region']) {
            $region = self::englishRegionName($tags['region']);

            return $language.' ('.($region ?? $tags['region']).')';
        }

        return $language;
    }

    private static function englishLanguageName(string $language): ?string
    {
        static $names = [
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'pl' => 'Polish',
            'cs' => 'Czech',
            'sk' => 'Slovak',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'ru' => 'Russian',
            'uk' => 'Ukrainian',
            'el' => 'Greek',
            'tr' => 'Turkish',
            'ar' => 'Arabic',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'vi' => 'Vietnamese',
            'th' => 'Thai',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'ca' => 'Catalan',
            'eu' => 'Basque',
            'gl' => 'Galician',
            'ga' => 'Irish',
            'cy' => 'Welsh',
            'is' => 'Icelandic',
            'lt' => 'Lithuanian',
            'lv' => 'Latvian',
            'et' => 'Estonian',
            'sl' => 'Slovenian',
            'hr' => 'Croatian',
            'sr' => 'Serbian',
            'bs' => 'Bosnian',
            'mk' => 'Macedonian',
            'sq' => 'Albanian',
            'af' => 'Afrikaans',
            'sw' => 'Swahili',
            'zu' => 'Zulu',
            'xh' => 'Xhosa',
            'bn' => 'Bengali',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'mr' => 'Marathi',
            'gu' => 'Gujarati',
            'kn' => 'Kannada',
            'ml' => 'Malayalam',
            'pa' => 'Punjabi',
            'ur' => 'Urdu',
            'fa' => 'Persian',
            'am' => 'Amharic',
            'yo' => 'Yoruba',
            'ig' => 'Igbo',
            'ha' => 'Hausa',
        ];

        return $names[$language] ?? null;
    }

    private static function englishRegionName(string $region): ?string
    {
        static $names = [
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'IE' => 'Ireland',
            'DE' => 'Germany',
            'FR' => 'France',
            'ES' => 'Spain',
            'IT' => 'Italy',
            'PT' => 'Portugal',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Peru',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'CZ' => 'Czechia',
            'SK' => 'Slovakia',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'RU' => 'Russia',
            'UA' => 'Ukraine',
            'GR' => 'Greece',
            'TR' => 'Turkey',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'CN' => 'China',
            'TW' => 'Taiwan',
            'HK' => 'Hong Kong',
            'SG' => 'Singapore',
            'IN' => 'India',
            'ID' => 'Indonesia',
            'TH' => 'Thailand',
            'VN' => 'Vietnam',
            'PH' => 'Philippines',
            'MY' => 'Malaysia',
            'ZA' => 'South Africa',
            'EG' => 'Egypt',
            'NG' => 'Nigeria',
            'KE' => 'Kenya',
            'IL' => 'Israel',
            'SA' => 'Saudi Arabia',
            'AE' => 'United Arab Emirates',
        ];

        return $names[$region] ?? null;
    }

    private static function resolveLocaleOperand(string $locale): string
    {
        if ('' === $locale) {
            return self::getDefault();
        }

        return $locale;
    }

    /**
     * @return array{language: string, script: string, region: string}
     */
    private static function parseBcp47Tags(string $locale): array
    {
        $locale = str_replace('_', '-', $locale);
        $segments = explode('-', $locale);
        $language = '';
        $script = '';
        $region = '';
        if ([] === $segments || '' === $segments[0]) {
            return ['language' => $language, 'script' => $script, 'region' => $region];
        }
        $language = strtolower($segments[0]);
        $count = \count($segments);
        for ($i = 1; $i < $count; ++$i) {
            $part = $segments[$i];
            if ('' === $part) {
                continue;
            }
            if ('' === $script && 4 === \strlen($part) && ctype_alpha($part)) {
                $script = self::canonicalScriptTag($part);
                continue;
            }
            if ('' === $region
                && ((2 === \strlen($part) && ctype_alpha($part))
                    || (3 === \strlen($part) && ctype_digit($part)))) {
                $region = strtoupper($part);
            }
        }

        return ['language' => $language, 'script' => $script, 'region' => $region];
    }

    private static function canonicalScriptTag(string $script): string
    {
        $lower = strtolower($script);

        return strtoupper($lower[0]).substr($lower, 1);
    }

    private static function detectSystemDefault(): string
    {
        foreach (['LC_ALL', 'LANG', 'LC_MESSAGES'] as $var) {
            $val = getenv($var);
            if (!\is_string($val) || '' === $val) {
                continue;
            }
            if ('C' === $val || 'POSIX' === $val) {
                return 'en_US_POSIX';
            }
            $tag = explode('.', $val, 2)[0];
            $tag = str_replace('-', '_', $tag);
            if (self::isValidLocaleId($tag)) {
                return $tag;
            }
        }

        return 'en_US_POSIX';
    }

    private static function assertValidLocaleId(string $locale): void
    {
        if (!self::isValidLocaleId($locale)) {
            throw new \ValueError(
                'locale_set_default(): Argument #1 ($locale) must be a valid locale'
            );
        }
    }

    private static function isValidLocaleId(string $locale): bool
    {
        if ('' === $locale) {
            return false;
        }

        return 1 === preg_match(
            '/^[a-zA-Z][a-zA-Z0-9]*(?:_[a-zA-Z0-9]+)*(?:@[a-zA-Z0-9_=\\-\\.,]+)?$/',
            $locale
        );
    }
}
