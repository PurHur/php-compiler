<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * Process default BCP-47 locale id (php-src ext/intl/php_intl.c / locale_methods.c).
 *
 * Issues #9576 / #6696 / #20036 / #20738. Prefer thin ICU FFI (`uloc_*`) when available;
 * PHP fallbacks cover hyphen/underscore normalize + `@keyword` parse without C runtime growth.
 */
final class VmLocale
{
    /** php-src INTL_MAX_LOCALE_LEN = ULOC_FULLNAME_CAPACITY - 1 */
    private const MAX_LOCALE_LEN = 156;

    /** @var array<string, true> ICU RTL scripts used by uloc_isRightToLeft fallback (#20927) */
    private const RTL_SCRIPTS = [
        'Arab' => true,
        'Hebr' => true,
        'Syrc' => true,
        'Thaa' => true,
        'Nkoo' => true,
        'Adlm' => true,
        'Mand' => true,
        'Mend' => true,
        'Narb' => true,
        'Rohg' => true,
        'Samr' => true,
    ];

    /** @var array<string, true> languages that maximize to an RTL script (CLDR likely subtags) */
    private const RTL_LANGUAGES = [
        'ar' => true,
        'arc' => true,
        'ckb' => true,
        'dv' => true,
        'fa' => true,
        'he' => true,
        'iw' => true,
        'ks' => true,
        'ps' => true,
        'sd' => true,
        'ug' => true,
        'ur' => true,
        'yi' => true,
    ];

    /** @var list<string> php-src LOC_GRANDFATHERED (hyphen form, case-insensitive match) */
    private const GRANDFATHERED = [
        'art-lojban', 'cel-gaulish', 'en-GB-oed', 'i-ami', 'i-bnn', 'i-default', 'i-enochian',
        'i-hak', 'i-klingon', 'i-lux', 'i-mingo', 'i-navajo', 'i-pwn', 'i-tao', 'i-tay', 'i-tsu',
        'no-bok', 'no-nyn', 'sgn-BE-FR', 'sgn-BE-NL', 'sgn-BR', 'sgn-CH-DE', 'sgn-CO', 'sgn-DE',
        'sgn-DK', 'sgn-ES', 'sgn-FR', 'sgn-GB', 'sgn-GR', 'sgn-IE', 'sgn-IT', 'sgn-JP', 'sgn-MX',
        'sgn-NI', 'sgn-NL', 'sgn-NO', 'sgn-PT', 'sgn-SE', 'sgn-US', 'sgn-ZA', 'zh-cmn',
        'zh-cmn-Hans', 'zh-cmn-Hant', 'zh-gan', 'zh-guoyu', 'zh-hakka', 'zh-min', 'zh-min-nan',
        'zh-wuu', 'zh-xiang',
    ];

    private static ?string $default = null;

    /** @var object|null FFI instance for uloc_* (libicui18n) */
    private static $ulocFfi = null;

    private static ?string $ulocSuffix = null;

    private static bool $ulocFfiResolved = false;

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
     * Z_PARAM_STR $locale for setDefault — null always TypeError (#29932, locale.stub.php).
     *
     * php-src locale_set_default / Locale::setDefault use Z_PARAM_STR. Do not soft-coerce via
     * {@see VmString::coerceStringBuiltinArg} (null→"" then ValueError on invalid locale).
     */
    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($locale) must be of type string, null given',
                $function,
                $position + 1
            ));
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale', 'string', false);
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
     * Locale::getDisplayName() — ICU uloc_getDisplayName (#6696, #22901).
     *
     * Falls back to a deterministic English approximation when libicu FFI is unavailable.
     *
     * @return string|false
     */
    public static function getDisplayName(string $locale, ?string $displayLocale = null): string|false
    {
        $id = self::resolveLocaleOperand($locale);
        $tags = self::parseBcp47Tags($id);
        if ('' === $tags['language']) {
            return false;
        }
        if (null === $displayLocale || '' === $displayLocale) {
            $displayLocale = self::getDefault();
        }
        $icu = self::ulocGetDisplayField('uloc_getDisplayName', $id, $displayLocale);
        if (null !== $icu) {
            return $icu;
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
     * Locale::getDisplayLanguage() — ICU uloc_getDisplayLanguage (#20755, #22901).
     *
     * @return string|false
     */
    public static function getDisplayLanguage(string $locale, ?string $displayLocale = null): string|false
    {
        $id = self::resolveLocaleOperand($locale);
        $tags = self::parseBcp47Tags($id);
        if ('' === $tags['language']) {
            return false;
        }
        if (null === $displayLocale || '' === $displayLocale) {
            $displayLocale = self::getDefault();
        }
        $icu = self::ulocGetDisplayField('uloc_getDisplayLanguage', $id, $displayLocale);
        if (null !== $icu) {
            return $icu;
        }

        return self::englishLanguageName($tags['language']) ?? $tags['language'];
    }

    /**
     * Locale::getDisplayRegion() — ICU uloc_getDisplayCountry (#20755, #22901).
     *
     * @return string|false
     */
    public static function getDisplayRegion(string $locale, ?string $displayLocale = null): string|false
    {
        $id = self::resolveLocaleOperand($locale);
        $tags = self::parseBcp47Tags($id);
        if ('' === $tags['region']) {
            return '';
        }
        if (null === $displayLocale || '' === $displayLocale) {
            $displayLocale = self::getDefault();
        }
        $icu = self::ulocGetDisplayField('uloc_getDisplayCountry', $id, $displayLocale);
        if (null !== $icu) {
            return $icu;
        }

        return self::englishRegionName($tags['region']) ?? $tags['region'];
    }

    /**
     * Locale::getDisplayScript() — ICU uloc_getDisplayScript (#20755, #22901).
     *
     * @return string|false
     */
    public static function getDisplayScript(string $locale, ?string $displayLocale = null): string|false
    {
        $id = self::resolveLocaleOperand($locale);
        $tags = self::parseBcp47Tags($id);
        if ('' === $tags['script']) {
            return '';
        }
        if (null === $displayLocale || '' === $displayLocale) {
            $displayLocale = self::getDefault();
        }
        $icu = self::ulocGetDisplayField('uloc_getDisplayScript', $id, $displayLocale);
        if (null !== $icu) {
            return $icu;
        }

        return self::englishScriptName($tags['script']) ?? $tags['script'];
    }

    /**
     * Locale::getDisplayVariant() — ICU uloc_getDisplayVariant (#20755, #22901).
     *
     * Single known variants get ICU-shaped English labels; multi-variant tags return
     * the underscore-joined raw codes when ICU is unavailable (php-src / ICU).
     *
     * @return string|false
     */
    public static function getDisplayVariant(string $locale, ?string $displayLocale = null): string|false
    {
        $id = self::resolveLocaleOperand($locale);
        $variants = self::variantSubtags($id);
        if ([] === $variants) {
            return '';
        }
        if (null === $displayLocale || '' === $displayLocale) {
            $displayLocale = self::getDefault();
        }
        $icu = self::ulocGetDisplayField('uloc_getDisplayVariant', $id, $displayLocale);
        if (null !== $icu) {
            return $icu;
        }
        if (1 === \count($variants)) {
            return self::englishVariantName($variants[0]) ?? $variants[0];
        }

        return implode('_', $variants);
    }

    /**
     * Locale::getAllVariants() — variant subtag list (#20755).
     *
     * @return list<string>
     */
    public static function getAllVariants(string $locale): array
    {
        return self::variantSubtags(self::resolveLocaleOperand($locale));
    }

    /**
     * locale_lookup() / Locale::lookup() — RFC 4647 lookup (php-src locale_methods.c; #20036, #20936).
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
     * locale_filter_matches() / Locale::filterMatches() — prefix filter (php-src; #20036, #20939).
     *
     * When {@code $canonicalize} is true, both tags are ICU-canonicalized first and a following
     * {@code @} keyword separator is allowed (php-src locale_methods.cpp); when false, only
     * ID separators / end-of-tag match after the prefix.
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
        if ($canonicalize) {
            IntlError::clear();
            $canLang = self::canonicalize($langtag);
            if (null === $canLang || '' === $canLang) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'unable to canonicalize lang_tag'
                );

                return false;
            }
            $canLoc = self::canonicalize($locale);
            if (null === $canLoc || '' === $canLoc) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'unable to canonicalize loc_range'
                );

                return false;
            }
            $langtag = $canLang;
            $locale = $canLoc;
        }
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
        if ('_' === $ch || '-' === $ch) {
            return true;
        }
        // php-src: keyword separator only accepted after canonicalize branch.
        if ($canonicalize && '@' === $ch) {
            return true;
        }

        return false;
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
     * locale_canonicalize() / Locale::canonicalize() — ICU uloc_canonicalize (#20738).
     *
     * @return string|null null when locale id exceeds INTL_MAX_LOCALE_LEN or ICU fails hard
     */
    public static function canonicalize(string $locale): ?string
    {
        IntlError::clear();
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        if (\strlen($locale) > self::MAX_LOCALE_LEN) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'Locale string too long, should be no longer than '.self::MAX_LOCALE_LEN.' characters'
            );

            return null;
        }
        $icu = self::ulocGetTag($locale, 'canonicalize');
        if (null !== $icu) {
            return $icu;
        }

        return self::canonicalizeFallback($locale);
    }

    /**
     * locale_parse() / Locale::parseLocale() — subtag map (#20738).
     *
     * @return array<string, string>|null
     */
    public static function parseLocale(string $locale): ?array
    {
        IntlError::clear();
        if (\strlen($locale) > self::MAX_LOCALE_LEN) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'Locale string too long, should be no longer than '.self::MAX_LOCALE_LEN.' characters'
            );

            return null;
        }
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        $gf = self::matchGrandfathered($locale);
        if (null !== $gf) {
            return ['grandfathered' => $locale];
        }
        $out = [];
        $lang = self::ulocGetTag($locale, 'language', true);
        if (null === $lang) {
            $lang = self::parseBcp47Tags($locale)['language'];
        }
        if ('' !== $lang) {
            $out['language'] = $lang;
        }
        $script = self::ulocGetTag($locale, 'script', true);
        if (null === $script) {
            $script = self::parseBcp47Tags($locale)['script'];
        }
        if ('' !== $script) {
            $out['script'] = $script;
        }
        $region = self::ulocGetTag($locale, 'region', true);
        if (null === $region) {
            $region = self::parseBcp47Tags($locale)['region'];
        }
        if ('' !== $region) {
            $out['region'] = $region;
        }
        $variant = self::ulocGetTag($locale, 'variant', true);
        if (null === $variant) {
            $variant = self::parseVariantFallback($locale);
        }
        if (null !== $variant && '' !== $variant) {
            self::addNumberedSubtags($out, 'variant', $variant);
        }
        $private = self::extractPrivateSubtags($locale);
        if (null !== $private && '' !== $private) {
            self::addNumberedSubtags($out, 'private', $private);
        }

        return $out;
    }

    /**
     * locale_compose() / Locale::composeLocale() — join subtag map (#20738).
     *
     * @param array<string|int, mixed> $subtags
     *
     * @return string|false
     */
    public static function composeLocale(array $subtags, string $function = 'Locale::composeLocale')
    {
        IntlError::clear();
        if ([] === $subtags) {
            return false;
        }
        if (isset($subtags['grandfathered'])) {
            if (!\is_string($subtags['grandfathered'])) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'locale_compose: parameter array element is not a string'
                );

                return false;
            }

            return $subtags['grandfathered'];
        }
        if (!isset($subtags['language'])) {
            throw new \ValueError($function.'(): Argument #1 ($subtags) must contain a "language" key');
        }
        if (!\is_string($subtags['language'])) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'locale_compose: parameter array element is not a string'
            );

            return false;
        }
        $parts = [$subtags['language']];
        $ext = self::collectNumberedOrList($subtags, 'extlang');
        if (false === $ext) {
            return false;
        }
        foreach ($ext as $v) {
            $parts[] = $v;
        }
        foreach (['script', 'region'] as $key) {
            if (!isset($subtags[$key])) {
                continue;
            }
            if (!\is_string($subtags[$key])) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'locale_compose: parameter array element is not a string'
                );

                return false;
            }
            $parts[] = $subtags[$key];
        }
        $variants = self::collectNumberedOrList($subtags, 'variant');
        if (false === $variants) {
            return false;
        }
        foreach ($variants as $v) {
            $parts[] = $v;
        }
        $private = self::collectNumberedOrList($subtags, 'private');
        if (false === $private) {
            return false;
        }
        if ([] !== $private) {
            $parts[] = 'x';
            foreach ($private as $v) {
                $parts[] = $v;
            }
        }

        return implode('_', $parts);
    }

    /**
     * locale_get_display_keyword() / Locale::getDisplayKeyword() — ICU uloc_getDisplayKeyword (#20928).
     *
     * @return string|false
     */
    public static function getDisplayKeyword(string $keyword, ?string $displayLocale = null): string|false
    {
        IntlError::clear();
        if (null === $displayLocale || '' === $displayLocale) {
            $displayLocale = self::getDefault();
        }
        $icu = self::ulocGetDisplayKeyword($keyword, $displayLocale);
        if (null !== $icu) {
            return $icu;
        }
        $fb = self::displayKeywordFallback($keyword);
        if (null !== $fb) {
            return $fb;
        }
        IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, 'unable to get locale keyword');

        return false;
    }

    /**
     * locale_get_display_keyword_value() / Locale::getDisplayKeywordValue() (#20928).
     *
     * @return string|false
     */
    public static function getDisplayKeywordValue(
        string $locale,
        string $keyword,
        ?string $displayLocale = null
    ): string|false {
        IntlError::clear();
        if (\strlen($locale) > self::MAX_LOCALE_LEN) {
            IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, 'name too long');

            return false;
        }
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        if (null === $displayLocale || '' === $displayLocale) {
            $displayLocale = self::getDefault();
        }
        $icu = self::ulocGetDisplayKeywordValue($locale, $keyword, $displayLocale);
        if (null !== $icu) {
            return $icu;
        }
        $fb = self::displayKeywordValueFallback($locale, $keyword);
        if (null !== $fb) {
            return $fb;
        }
        IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, 'unable to get locale keywordvalue');

        return false;
    }

    /**
     * locale_is_right_to_left() / Locale::isRightToLeft() — ICU uloc_isRightToLeft (#20927).
     *
     * php-src: ext/intl/locale/locale_methods.cpp (GH-18345). Empty locale → process default.
     */
    public static function isRightToLeft(string $locale): bool
    {
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        $icu = self::ulocIsRightToLeft($locale);
        if (null !== $icu) {
            return $icu;
        }

        return self::isRightToLeftFallback($locale);
    }

    /**
     * locale_add_likely_subtags() / Locale::addLikelySubtags() — ICU uloc_addLikelySubtags (#20927).
     *
     * @return string|false
     */
    public static function addLikelySubtags(string $locale): string|false
    {
        IntlError::clear();
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        $icu = self::ulocTransformSubtags($locale, 'addLikely');
        if (null !== $icu) {
            return $icu;
        }
        $fb = self::addLikelySubtagsFallback($locale);
        if (null !== $fb) {
            return $fb;
        }
        IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, 'invalid locale');

        return false;
    }

    /**
     * locale_minimize_subtags() / Locale::minimizeSubtags() — ICU uloc_minimizeSubtags (#20927).
     *
     * @return string|false
     */
    public static function minimizeSubtags(string $locale): string|false
    {
        IntlError::clear();
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        $icu = self::ulocTransformSubtags($locale, 'minimize');
        if (null !== $icu) {
            return $icu;
        }
        $fb = self::minimizeSubtagsFallback($locale);
        if (null !== $fb) {
            return $fb;
        }
        IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, 'invalid locale');

        return false;
    }

    /**
     * locale_get_keywords() / Locale::getKeywords() — @keyword map (#20738).
     *
     * @return array<string, string>|false|null null when no keywords; false on ICU error
     */
    public static function getKeywords(string $locale)
    {
        IntlError::clear();
        if (\strlen($locale) > self::MAX_LOCALE_LEN) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'Locale string too long, should be no longer than '.self::MAX_LOCALE_LEN.' characters'
            );

            return null;
        }
        if ('' === $locale) {
            $locale = self::getDefault();
        }
        $icu = self::ulocOpenKeywords($locale);
        if (null !== $icu) {
            return $icu;
        }

        return self::getKeywordsFallback($locale);
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
                    'Locale::lookup(): Argument #1 ($languageTag) must only contain string values'
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
                // php-src: uloc_canonicalize(strToMatch(tag)) then strToMatch again (#20936 / bug #72809).
                $can = self::canonicalize($norm);
                if (null === $can || '' === $can) {
                    IntlError::set(
                        IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                        'lookup_loc_range: unable to canonicalize lang_tag'
                    );

                    return null;
                }
                $norm = self::strToMatch($can) ?? $can;
            }
            $cur[] = [$norm, $tag];
        }
        if ($canonicalize) {
            // php-src canonicalizes the original loc_range (not the strToMatch form first).
            $can = self::canonicalize($locRange);
            if (null === $can || '' === $can) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'lookup_loc_range: unable to canonicalize loc_range'
                );

                return null;
            }
            $locRange = $can;
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
                // php-src locale_methods.c: INTL_CHECK_STATUS(status, "…failed to find acceptable locale")
                // then RETURN_FALSE when len < 0 || ULOC_ACCEPT_FAILED (no extra error if status OK).
                if ((int) $status->cdata > 0) {
                    $code = (int) $status->cdata;
                    $name = IntlError::errorName($code);
                    IntlError::set(
                        $code,
                        'locale_accept_from_http: failed to find acceptable locale: '.$name
                    );

                    return false;
                }
                if ($len < 0 || 0 === (int) $out->cdata) {
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

    private static function englishScriptName(string $script): ?string
    {
        static $names = [
            'Hans' => 'Simplified Han',
            'Hant' => 'Traditional Han',
            'Latn' => 'Latin',
            'Cyrl' => 'Cyrillic',
            'Arab' => 'Arabic',
            'Grek' => 'Greek',
            'Hebr' => 'Hebrew',
            'Deva' => 'Devanagari',
            'Thai' => 'Thai',
            'Jpan' => 'Japanese',
            'Kore' => 'Korean',
            'Hang' => 'Hangul',
            'Hira' => 'Hiragana',
            'Kana' => 'Katakana',
        ];

        return $names[$script] ?? null;
    }

    private static function englishVariantName(string $variant): ?string
    {
        static $names = [
            'POSIX' => 'Computer',
            'NEDIS' => 'Natisone dialect',
            '1901' => 'Traditional German orthography',
            '1996' => 'German orthography of 1996',
            'ROJAZ' => 'Resian',
            'ALBA' => 'Albanian',
        ];

        return $names[strtoupper($variant)] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function variantSubtags(string $locale): array
    {
        $raw = self::parseVariantFallback($locale);
        if (null === $raw || '' === $raw) {
            return [];
        }
        $out = [];
        foreach (preg_split('/[_-]/', $raw) ?: [] as $v) {
            if ('' !== $v) {
                $out[] = strtoupper($v);
            }
        }

        return $out;
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

    /**
     * Idle Locale::getDefault() / locale_get_default() (php-src locale_methods.c + ICU).
     *
     * Prefer non-empty intl.default_locale; else env LC_ALL / LANG / LC_MESSAGES with charset stripped.
     * Bare C / POSIX (including C.UTF-8 → C) map to en_US_POSIX like Zend/ICU (#22578).
     */
    private static function detectSystemDefault(): string
    {
        $ini = \ini_get('intl.default_locale');
        if (\is_string($ini) && '' !== $ini) {
            $fromIni = str_replace('-', '_', $ini);
            if (self::isValidLocaleId($fromIni)) {
                return $fromIni;
            }
        }

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
            // C.UTF-8 / POSIX.UTF-8 strip to C/POSIX — Zend maps these to en_US_POSIX (#22578).
            if ('C' === $tag || 'POSIX' === $tag) {
                return 'en_US_POSIX';
            }
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

    /** Case-insensitive match against php-src LOC_GRANDFATHERED (hyphen form). */
    private static function matchGrandfathered(string $locale): ?string
    {
        $needle = strtolower(str_replace('_', '-', $locale));
        foreach (self::GRANDFATHERED as $tag) {
            if (strtolower($tag) === $needle) {
                return $tag;
            }
        }

        return null;
    }

    /** @return bool|null null = ICU unavailable */
    private static function ulocIsRightToLeft(string $locale): ?bool
    {
        $ffi = self::ulocFfi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$ulocSuffix ?? '';
        $fn = 'uloc_isRightToLeft'.$suffix;
        try {
            return (bool) $ffi->$fn($locale);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return string|false|null null = ICU unavailable; false = ICU error
     */
    private static function ulocGetDisplayKeyword(string $keyword, string $displayLocale): string|false|null
    {
        return self::ulocDisplayKeywordCall('uloc_getDisplayKeyword', [$keyword, $displayLocale]);
    }

    /**
     * Locale display field via ICU (name/language/country/script/variant) — #22901.
     *
     * @return string|false|null null = ICU unavailable; false = ICU error
     */
    private static function ulocGetDisplayField(string $baseFn, string $locale, string $displayLocale): string|false|null
    {
        return self::ulocDisplayKeywordCall($baseFn, [$locale, $displayLocale]);
    }

    /**
     * @return string|false|null null = ICU unavailable; false = ICU error
     */
    private static function ulocGetDisplayKeywordValue(
        string $locale,
        string $keyword,
        string $displayLocale
    ): string|false|null {
        return self::ulocDisplayKeywordCall(
            'uloc_getDisplayKeywordValue',
            [$locale, $keyword, $displayLocale]
        );
    }

    /**
     * @param list<string> $args leading string args before UChar* dest
     *
     * @return string|false|null
     */
    private static function ulocDisplayKeywordCall(string $baseFn, array $args): string|false|null
    {
        $ffi = self::ulocFfi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$ulocSuffix ?? '';
        $fn = $baseFn.$suffix;
        try {
            $status = $ffi->new('UErrorCode');
            $buflen = 512;
            $buf = $ffi->new('UChar['.$buflen.']');
            $status->cdata = 0;
            if (2 === \count($args)) {
                $len = (int) $ffi->$fn($args[0], $args[1], $buf, $buflen, \FFI::addr($status));
            } else {
                $len = (int) $ffi->$fn($args[0], $args[1], $args[2], $buf, $buflen, \FFI::addr($status));
            }
            if (15 === (int) $status->cdata) { // U_BUFFER_OVERFLOW_ERROR
                $buflen = $len + 1;
                $buf = $ffi->new('UChar['.$buflen.']');
                $status->cdata = 0;
                if (2 === \count($args)) {
                    $len = (int) $ffi->$fn($args[0], $args[1], $buf, $buflen, \FFI::addr($status));
                } else {
                    $len = (int) $ffi->$fn($args[0], $args[1], $args[2], $buf, $buflen, \FFI::addr($status));
                }
            }
            // U_STRING_NOT_TERMINATED_WARNING (-124) is admissible (php-src).
            $code = (int) $status->cdata;
            if ($code > 0) {
                return false;
            }
            if ($len < 0) {
                return false;
            }
            if (0 === $len) {
                return '';
            }

            return self::utf16BufferToUtf8($buf, $len);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param \FFI\CData $buf UChar[]
     */
    private static function utf16BufferToUtf8(\FFI\CData $buf, int $len): string
    {
        $out = '';
        $i = 0;
        while ($i < $len) {
            $u = (int) $buf[$i] & 0xFFFF;
            if ($u >= 0xD800 && $u <= 0xDBFF && ($i + 1) < $len) {
                $u2 = (int) $buf[$i + 1] & 0xFFFF;
                if ($u2 >= 0xDC00 && $u2 <= 0xDFFF) {
                    $cp = 0x10000 + (($u - 0xD800) << 10) + ($u2 - 0xDC00);
                    $out .= UnicodeCanonical::codepointToUtf8($cp);
                    $i += 2;
                    continue;
                }
            }
            $out .= UnicodeCanonical::codepointToUtf8($u);
            ++$i;
        }

        return $out;
    }

    private static function displayKeywordFallback(string $keyword): ?string
    {
        static $en = [
            'currency' => 'Currency',
            'collation' => 'Sort Order',
            'calendar' => 'Calendar',
            'numbers' => 'Numbers',
            'hours' => 'Hour Cycle',
        ];

        return $en[strtolower($keyword)] ?? null;
    }

    private static function displayKeywordValueFallback(string $locale, string $keyword): ?string
    {
        $keywords = self::getKeywordsFallback($locale) ?? self::getKeywords($locale);
        if (!\is_array($keywords)) {
            return null;
        }
        $val = $keywords[strtolower($keyword)] ?? $keywords[$keyword] ?? null;
        if (!\is_string($val) || '' === $val) {
            return null;
        }
        if ('currency' === strtolower($keyword)) {
            static $currency = [
                'EUR' => 'Euro',
                'USD' => 'US Dollar',
                'GBP' => 'British Pound',
                'JPY' => 'Japanese Yen',
            ];

            return $currency[strtoupper($val)] ?? $val;
        }

        return $val;
    }

    /**
     * @param 'addLikely'|'minimize' $kind
     *
     * @return string|false|null null = ICU unavailable; false = ICU error
     */
    private static function ulocTransformSubtags(string $locale, string $kind): string|false|null
    {
        $ffi = self::ulocFfi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$ulocSuffix ?? '';
        $fn = ('addLikely' === $kind ? 'uloc_addLikelySubtags' : 'uloc_minimizeSubtags').$suffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $buflen = 157;
            $buf = $ffi->new('char['.$buflen.']');
            $len = (int) $ffi->$fn($locale, $buf, $buflen - 1, \FFI::addr($status));
            if (15 === (int) $status->cdata) { // U_BUFFER_OVERFLOW_ERROR
                $status->cdata = 0;
                $buflen = $len + 1;
                $buf = $ffi->new('char['.$buflen.']');
                $len = (int) $ffi->$fn($locale, $buf, $buflen, \FFI::addr($status));
            }
            if ((int) $status->cdata > 0) {
                IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, 'invalid locale');

                return false;
            }
            if ($len < 0) {
                return false;
            }

            return $len > 0 ? \FFI::string($buf, $len) : \FFI::string($buf);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isRightToLeftFallback(string $locale): bool
    {
        $tags = self::parseBcp47Tags($locale);
        if ('' !== $tags['script']) {
            return isset(self::RTL_SCRIPTS[$tags['script']]);
        }
        if ('' !== $tags['language']) {
            return isset(self::RTL_LANGUAGES[$tags['language']]);
        }

        return false;
    }

    /** @return string|null */
    private static function addLikelySubtagsFallback(string $locale): ?string
    {
        static $table = [
            'en' => 'en_Latn_US',
            'en_US' => 'en_Latn_US',
            'ar' => 'ar_Arab_EG',
            'ar_EG' => 'ar_Arab_EG',
            'zh' => 'zh_Hans_CN',
            'zh_CN' => 'zh_Hans_CN',
            'ja' => 'ja_Jpan_JP',
            'he' => 'he_Hebr_IL',
            'fa' => 'fa_Arab_IR',
            'ur' => 'ur_Arab_PK',
            'de' => 'de_Latn_DE',
            'fr' => 'fr_Latn_FR',
        ];
        $key = str_replace('-', '_', $locale);
        if (isset($table[$key])) {
            return $table[$key];
        }
        $lower = strtolower($key);
        foreach ($table as $k => $v) {
            if (strtolower($k) === $lower) {
                return $v;
            }
        }

        return null;
    }

    /** @return string|null */
    private static function minimizeSubtagsFallback(string $locale): ?string
    {
        static $table = [
            'en_Latn_US' => 'en',
            'ar_Arab_EG' => 'ar',
            'zh_Hans_CN' => 'zh',
            'ja_Jpan_JP' => 'ja',
            'he_Hebr_IL' => 'he',
            'fa_Arab_IR' => 'fa',
            'ur_Arab_PK' => 'ur',
            'de_Latn_DE' => 'de',
            'fr_Latn_FR' => 'fr',
        ];
        $key = str_replace('-', '_', $locale);
        if (isset($table[$key])) {
            return $table[$key];
        }
        $canon = self::canonicalizeFallback($locale);
        if (isset($table[$canon])) {
            return $table[$canon];
        }
        // Already minimal language-only tag.
        $tags = self::parseBcp47Tags($locale);
        if ('' !== $tags['language'] && '' === $tags['script'] && '' === $tags['region']) {
            return $tags['language'];
        }

        return null;
    }

    /**
     * ICU uloc_canonicalize / getLanguage / getScript / getCountry / getVariant.
     *
     * @return string|null null = ICU unavailable; "" = empty ICU result
     */
    private static function ulocGetTag(string $locale, string $tag, bool $fromParseLocale = false): ?string
    {
        $ffi = self::ulocFfi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$ulocSuffix ?? '';
        $fn = match ($tag) {
            'canonicalize' => 'uloc_canonicalize'.$suffix,
            'language' => 'uloc_getLanguage'.$suffix,
            'script' => 'uloc_getScript'.$suffix,
            'region' => 'uloc_getCountry'.$suffix,
            'variant' => 'uloc_getVariant'.$suffix,
            default => null,
        };
        if (null === $fn) {
            return null;
        }
        $mod = $locale;
        if ($fromParseLocale && 'canonicalize' !== $tag) {
            $singletonPos = self::getSingletonPos($locale);
            if (0 === $singletonPos) {
                return '';
            }
            if ($singletonPos > 0) {
                $mod = substr($locale, 0, $singletonPos - 1);
            }
            if ('language' === $tag && \strlen($locale) > 1 && self::isIdPrefix($locale)) {
                return $locale;
            }
        }
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $buflen = 157;
            $buf = $ffi->new('char['.$buflen.']');
            $len = (int) $ffi->$fn($mod, $buf, $buflen - 1, \FFI::addr($status));
            if ((int) $status->cdata > 0 && 15 !== (int) $status->cdata) { // U_BUFFER_OVERFLOW_ERROR=15
                return null;
            }
            if (15 === (int) $status->cdata) {
                $status->cdata = 0;
                $buflen = $len + 1;
                $buf = $ffi->new('char['.$buflen.']');
                $len = (int) $ffi->$fn($mod, $buf, $buflen, \FFI::addr($status));
            }
            if ((int) $status->cdata > 0) {
                return null;
            }
            if ($len <= 0) {
                return '';
            }

            return \FFI::string($buf, $len);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>|false|null null = no keywords / ICU unavailable for empty;
     *                                         false = error; array = keywords (possibly empty)
     */
    private static function ulocOpenKeywords(string $locale)
    {
        $ffi = self::ulocFfi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$ulocSuffix ?? '';
        $open = 'uloc_openKeywords'.$suffix;
        $next = 'uenum_next'.$suffix;
        $close = 'uenum_close'.$suffix;
        $getKw = 'uloc_getKeywordValue'.$suffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $en = $ffi->$open($locale, \FFI::addr($status));
            if ((int) $status->cdata > 0 || null === $en) {
                return null;
            }
            $out = [];
            while (true) {
                $status->cdata = 0;
                $keyLen = $ffi->new('int32_t');
                $keyLen->cdata = 0;
                $keyPtr = $ffi->$next($en, \FFI::addr($keyLen), \FFI::addr($status));
                if (null === $keyPtr) {
                    break;
                }
                $key = \FFI::string($keyPtr);
                $status->cdata = 0;
                $vlen = 100;
                $vbuf = $ffi->new('char['.$vlen.']');
                $got = (int) $ffi->$getKw($locale, $key, $vbuf, $vlen, \FFI::addr($status));
                if (15 === (int) $status->cdata) {
                    $status->cdata = 0;
                    $vlen = $got + 1;
                    $vbuf = $ffi->new('char['.$vlen.']');
                    $got = (int) $ffi->$getKw($locale, $key, $vbuf, $vlen, \FFI::addr($status));
                }
                if ((int) $status->cdata > 0) {
                    $ffi->$close($en);
                    IntlError::set(
                        IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                        'locale_get_keywords: Error encountered while getting the keyword value for the keyword'
                    );

                    return false;
                }
                $out[$key] = $got > 0 ? \FFI::string($vbuf, $got) : \FFI::string($vbuf);
            }
            $ffi->$close($en);

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return object|null */
    private static function ulocFfi()
    {
        if (self::$ulocFfiResolved) {
            return self::$ulocFfi;
        }
        self::$ulocFfiResolved = true;
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            return null;
        }
        $candidates = [
            ['libicui18n.so.70', '_70'],
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so', '_70'],
            ['libicui18n.dylib', ''],
        ];
        foreach ($candidates as [$lib, $suffix]) {
            try {
                $cdef = <<<C
typedef int32_t UErrorCode;
typedef int8_t UBool;
typedef uint16_t UChar;
typedef struct UEnumeration UEnumeration;
int32_t uloc_canonicalize{$suffix}(const char *localeID, char *name, int32_t nameCapacity, UErrorCode *err);
int32_t uloc_getLanguage{$suffix}(const char *localeID, char *language, int32_t languageCapacity, UErrorCode *err);
int32_t uloc_getScript{$suffix}(const char *localeID, char *script, int32_t scriptCapacity, UErrorCode *err);
int32_t uloc_getCountry{$suffix}(const char *localeID, char *country, int32_t countryCapacity, UErrorCode *err);
int32_t uloc_getVariant{$suffix}(const char *localeID, char *variant, int32_t variantCapacity, UErrorCode *err);
UBool uloc_isRightToLeft{$suffix}(const char *locale);
int32_t uloc_addLikelySubtags{$suffix}(const char *localeID, char *maximizedLocaleID, int32_t maximizedLocaleIDCapacity, UErrorCode *err);
int32_t uloc_minimizeSubtags{$suffix}(const char *localeID, char *minimizedLocaleID, int32_t minimizedLocaleIDCapacity, UErrorCode *err);
int32_t uloc_getDisplayKeyword{$suffix}(const char *keyword, const char *displayLocale, UChar *dest, int32_t destCapacity, UErrorCode *status);
int32_t uloc_getDisplayKeywordValue{$suffix}(const char *locale, const char *keyword, const char *displayLocale, UChar *dest, int32_t destCapacity, UErrorCode *status);
int32_t uloc_getDisplayName{$suffix}(const char *localeID, const char *inLocaleID, UChar *result, int32_t maxResultSize, UErrorCode *err);
int32_t uloc_getDisplayLanguage{$suffix}(const char *locale, const char *displayLocale, UChar *dest, int32_t destCapacity, UErrorCode *status);
int32_t uloc_getDisplayCountry{$suffix}(const char *locale, const char *displayLocale, UChar *dest, int32_t destCapacity, UErrorCode *status);
int32_t uloc_getDisplayScript{$suffix}(const char *locale, const char *displayLocale, UChar *dest, int32_t destCapacity, UErrorCode *status);
int32_t uloc_getDisplayVariant{$suffix}(const char *locale, const char *displayLocale, UChar *dest, int32_t destCapacity, UErrorCode *status);
UEnumeration *uloc_openKeywords{$suffix}(const char *localeID, UErrorCode *status);
const char *uenum_next{$suffix}(UEnumeration *en, int32_t *resultLength, UErrorCode *status);
void uenum_close{$suffix}(UEnumeration *en);
int32_t uloc_getKeywordValue{$suffix}(const char *localeID, const char *keywordName, char *buffer, int32_t bufferCapacity, UErrorCode *status);
C;
                self::$ulocFfi = \FFI::cdef($cdef, $lib);
                self::$ulocSuffix = $suffix;

                return self::$ulocFfi;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private static function canonicalizeFallback(string $locale): string
    {
        $at = '';
        $base = $locale;
        $atPos = strpos($locale, '@');
        if (false !== $atPos) {
            $base = substr($locale, 0, $atPos);
            $at = substr($locale, $atPos);
        }
        $tags = self::parseBcp47Tags($base);
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
        $variant = self::parseVariantFallback($base);
        if (null !== $variant && '' !== $variant) {
            foreach (preg_split('/[_-]/', $variant) ?: [] as $v) {
                if ('' !== $v) {
                    $parts[] = strtoupper($v);
                }
            }
        }

        return implode('_', $parts).$at;
    }

    /**
     * @return array<string, string>|null
     */
    private static function getKeywordsFallback(string $locale): ?array
    {
        $atPos = strpos($locale, '@');
        if (false === $atPos) {
            return null;
        }
        $raw = substr($locale, $atPos + 1);
        if ('' === $raw) {
            return [];
        }
        $out = [];
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ('' === $pair) {
                continue;
            }
            $eq = strpos($pair, '=');
            if (false === $eq) {
                continue;
            }
            $key = trim(substr($pair, 0, $eq));
            $val = trim(substr($pair, $eq + 1));
            if ('' !== $key) {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    private static function parseVariantFallback(string $locale): ?string
    {
        $locale = str_replace('_', '-', $locale);
        $atPos = strpos($locale, '@');
        if (false !== $atPos) {
            $locale = substr($locale, 0, $atPos);
        }
        $singleton = self::getSingletonPos($locale);
        if ($singleton > 0) {
            $locale = substr($locale, 0, $singleton - 1);
        }
        $segments = explode('-', $locale);
        if (\count($segments) < 2) {
            return null;
        }
        $i = 1;
        $n = \count($segments);
        if ($i < $n && 4 === \strlen($segments[$i]) && ctype_alpha($segments[$i])) {
            ++$i; // script
        }
        if ($i < $n && ((2 === \strlen($segments[$i]) && ctype_alpha($segments[$i]))
            || (3 === \strlen($segments[$i]) && ctype_digit($segments[$i])))) {
            ++$i; // region
        }
        $variants = [];
        for (; $i < $n; ++$i) {
            $part = $segments[$i];
            if ('' === $part || 1 === \strlen($part)) {
                break;
            }
            $variants[] = $part;
        }
        if ([] === $variants) {
            return null;
        }

        return implode('_', $variants);
    }

    private static function extractPrivateSubtags(string $locale): ?string
    {
        $len = \strlen($locale);
        if ($len < 1) {
            return null;
        }
        $mod = $locale;
        $modLen = $len;
        while (($singletonPos = self::getSingletonPos($mod)) > -1) {
            $ch = $mod[$singletonPos];
            if ('x' === $ch || 'X' === $ch) {
                if ($singletonPos + 2 >= $modLen) {
                    return null;
                }

                return substr($mod, $singletonPos + 2);
            }
            if ($singletonPos + 1 >= $modLen) {
                break;
            }
            $mod = substr($mod, $singletonPos + 1);
            $modLen = \strlen($mod);
        }

        return null;
    }

    /**
     * php-src getSingletonPos — index of singleton subtag char, 0 if leading, or -1.
     * Separators are '_' / '-'; a singleton is one char between two separators (…-x-…).
     */
    private static function getSingletonPos(string $str): int
    {
        $len = \strlen($str);
        if ($len < 1) {
            return -1;
        }
        for ($i = 0; $i < $len; ++$i) {
            $ch = $str[$i];
            if ('_' !== $ch && '-' !== $ch) {
                continue;
            }
            if (1 === $i) {
                return 0;
            }
            if ($i + 2 < $len && ('_' === $str[$i + 2] || '-' === $str[$i + 2])) {
                return $i + 1;
            }
        }

        return -1;
    }

    private static function isIdPrefix(string $locale): bool
    {
        return 1 === preg_match('/^[iIxX][_-]/', $locale);
    }

    /**
     * @param array<string, string> $out
     */
    private static function addNumberedSubtags(array &$out, string $prefix, string $value): void
    {
        $tokens = preg_split('/[_-]/', $value) ?: [];
        $cnt = 0;
        foreach ($tokens as $token) {
            if ('' === $token || 1 === \strlen($token)) {
                break;
            }
            $out[$prefix.$cnt] = $token;
            ++$cnt;
        }
    }

    /**
     * @param array<string|int, mixed> $subtags
     *
     * @return list<string>|false
     */
    private static function collectNumberedOrList(array $subtags, string $key)
    {
        if (isset($subtags[$key])) {
            $ele = $subtags[$key];
            if (\is_string($ele)) {
                return [$ele];
            }
            if (\is_array($ele)) {
                $out = [];
                foreach ($ele as $data) {
                    if (!\is_string($data)) {
                        IntlError::set(
                            IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                            'locale_compose: parameter array element is not a string'
                        );

                        return false;
                    }
                    $out[] = $data;
                }

                return $out;
            }
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'locale_compose: parameter array element is not a string'
            );

            return false;
        }
        $out = [];
        for ($i = 0; $i < 15; ++$i) {
            $cur = $key.$i;
            if (!isset($subtags[$cur])) {
                break;
            }
            if (!\is_string($subtags[$cur])) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'locale_compose: parameter array element is not a string'
                );

                return false;
            }
            $out[] = $subtags[$cur];
        }

        return $out;
    }
}
