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

    /**
     * locale_lookup() / Locale::lookup() — RFC 4647 lookup (php-src locale_methods.c; #20036).
     *
     * @param list<string> $langtag
     */
    public static function lookup(
        array $langtag,
        string $locale,
        bool $canonicalize = false,
        ?string $defaultLocale = null
    ): string {
        if ([] === $langtag) {
            return '';
        }
        if ('' === $locale) {
            $locale = null !== $defaultLocale && '' !== $defaultLocale
                ? $defaultLocale
                : self::getDefault();
        }
        $matched = self::lookupLocRange($locale, $langtag, $canonicalize);
        if (null === $matched || '' === $matched) {
            return null !== $defaultLocale ? $defaultLocale : '';
        }

        return $matched;
    }

    /**
     * locale_filter_matches() / Locale::filterMatches() — prefix filter (php-src; #20036).
     */
    public static function filterMatches(
        string $langtag,
        string $locale,
        bool $canonicalize = false
    ): bool {
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        if ('*' === $locale) {
            return true;
        }
        unset($canonicalize); // ICU canonicalize path deferred; non-canonical match matches php-src |b=false
        $curLang = self::strToMatch($langtag);
        $curRange = self::strToMatch($locale);
        if (null === $curLang || null === $curRange) {
            return false;
        }
        if (!str_starts_with($curLang, $curRange)) {
            return false;
        }
        $next = \strlen($curRange);
        if ($next >= \strlen($curLang)) {
            return true;
        }
        $ch = $curLang[$next];

        return '_' === $ch || '-' === $ch || '@' === $ch;
    }

    /**
     * locale_accept_from_http() / Locale::acceptFromHttp() — Accept-Language (#20036).
     *
     * Prefers ICU uloc_acceptLanguageFromHTTP via thin FFI; PHP q-value parse fallback.
     *
     * @return string|false
     */
    public static function acceptFromHttp(string $header)
    {
        if (self::httpAcceptFragmentTooLong($header)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'locale_accept_from_http: locale string too long'
            );

            return false;
        }
        $icu = self::acceptFromHttpIcu($header);
        if (null !== $icu) {
            return $icu;
        }

        return self::acceptFromHttpFallback($header);
    }

    /**
     * @param list<string> $langtag
     */
    private static function lookupLocRange(string $locRange, array $langtag, bool $canonicalize): ?string
    {
        /** @var list<array{0: string, 1: string}> normalized + original */
        $cur = [];
        foreach ($langtag as $tag) {
            if (!\is_string($tag)) {
                throw new \TypeError(
                    'Locale::lookup(): Argument #1 ($langtag) must only contain string values'
                );
            }
            $norm = self::strToMatch($tag);
            if (null === $norm) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'lookup_loc_range: unable to canonicalize lang_tag'
                );

                return null;
            }
            if ($canonicalize) {
                $norm = self::lightweightCanonicalize($norm);
            }
            $cur[] = [$norm, $tag];
        }
        if ($canonicalize) {
            $locRange = self::lightweightCanonicalize(self::strToMatch($locRange) ?? $locRange);
        }
        $curLoc = self::strToMatch($locRange);
        if (null === $curLoc) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'lookup_loc_range: unable to canonicalize loc_range'
            );

            return null;
        }
        $savedPos = \strlen($curLoc);
        while ($savedPos > 0) {
            foreach ($cur as [$norm, $orig]) {
                if (\strlen($norm) === $savedPos && 0 === substr_compare($curLoc, $norm, 0, $savedPos)) {
                    return $canonicalize ? $norm : $orig;
                }
            }
            $savedPos = self::getStrrTokenPos($curLoc, $savedPos);
        }

        return null;
    }

    /** php-src strToMatch — lower + hyphen→underscore */
    private static function strToMatch(string $tag): ?string
    {
        if ('' === $tag) {
            return '';
        }
        $out = '';
        $len = \strlen($tag);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $tag[$i];
            if ('-' === $ch) {
                $out .= '_';
            } else {
                $out .= strtolower($ch);
            }
        }

        return $out;
    }

    /** php-src getStrrtokenPos — reverse token delimiter for lookup truncation */
    private static function getStrrTokenPos(string $str, int $savedPos): int
    {
        $result = -1;
        for ($i = $savedPos - 1; $i >= 0; --$i) {
            $ch = $str[$i];
            if ('_' === $ch || '-' === $ch || '@' === $ch) {
                if ($i >= 2 && ('_' === $str[$i - 2] || '-' === $str[$i - 2])) {
                    $result = $i - 2;
                } else {
                    $result = $i;
                }
                break;
            }
        }
        if ($result < 1) {
            return -1;
        }

        return $result;
    }

    /** Approximate ICU canonicalize when FFI unavailable (underscore form, lower language). */
    private static function lightweightCanonicalize(string $normalized): string
    {
        $tags = self::parseBcp47Tags(str_replace('_', '-', $normalized));
        $parts = [];
        if ('' !== $tags['language']) {
            $parts[] = $tags['language'];
        }
        if ('' !== $tags['script']) {
            $parts[] = $tags['script'];
        }
        if ('' !== $tags['region']) {
            $parts[] = $tags['region'];
        }

        return strtolower(implode('_', $parts));
    }

    private static function httpAcceptFragmentTooLong(string $header): bool
    {
        // ULOC_FULLNAME_CAPACITY ≈ 157
        $capacity = 157;
        if (\strlen($header) <= $capacity) {
            return false;
        }
        foreach (explode(',', $header) as $frag) {
            if (\strlen(trim($frag)) > $capacity) {
                return true;
            }
        }

        return false;
    }

    /** @return string|false|null null = ICU unavailable */
    private static function acceptFromHttpIcu(string $header)
    {
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            return null;
        }
        $candidates = [
            ['libicui18n.so.70', '_70'],
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so', '_70'],
            ['libicui18n.dylib', ''],
        ];
        $cdef = static function (string $suffix): string {
            return <<<C
typedef int32_t UErrorCode;
typedef struct UEnumeration UEnumeration;
typedef enum { ULOC_ACCEPT_FAILED=0, ULOC_ACCEPT_VALID=1, ULOC_ACCEPT_FALLBACK=2 } UAcceptResult;
UEnumeration *ures_openAvailableLocales{$suffix}(const char *packageName, UErrorCode *status);
void uenum_close{$suffix}(UEnumeration *en);
int32_t uloc_acceptLanguageFromHTTP{$suffix}(char *result, int32_t resultAvailable, UAcceptResult *outResult, const char *httpAcceptLanguage, UEnumeration *availableLocales, UErrorCode *status);
C;
        };
        foreach ($candidates as [$lib, $suffix]) {
            try {
                $ffi = \FFI::cdef($cdef($suffix), $lib);
                $status = $ffi->new('UErrorCode');
                $status->cdata = 0;
                $open = 'ures_openAvailableLocales'.$suffix;
                $close = 'uenum_close'.$suffix;
                $accept = 'uloc_acceptLanguageFromHTTP'.$suffix;
                $en = $ffi->$open(null, \FFI::addr($status));
                if ((int) $status->cdata > 0 || null === $en) {
                    continue;
                }
                $status->cdata = 0;
                $out = $ffi->new('UAcceptResult');
                $buf = $ffi->new('char[157]');
                $len = (int) $ffi->$accept($buf, 156, \FFI::addr($out), $header, $en, \FFI::addr($status));
                $ffi->$close($en);
                if ((int) $status->cdata > 0 || $len < 0 || 0 === (int) $out->cdata) {
                    IntlError::clear();

                    return false;
                }
                IntlError::clear();

                return \FFI::string($buf);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @return string|false */
    private static function acceptFromHttpFallback(string $header)
    {
        $bestTag = null;
        $bestQ = -1.0;
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            $pieces = array_map('trim', explode(';', $part));
            $tag = $pieces[0];
            if ('' === $tag || '*' === $tag) {
                continue;
            }
            $q = 1.0;
            for ($i = 1, $n = \count($pieces); $i < $n; ++$i) {
                if (1 === preg_match('/^q\s*=\s*([0-9.]+)$/i', $pieces[$i], $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q > $bestQ) {
                $bestQ = $q;
                $bestTag = str_replace('-', '_', $tag);
            }
        }
        if (null === $bestTag) {
            return false;
        }

        return $bestTag;
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
