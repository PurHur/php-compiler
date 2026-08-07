<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * intl extension module entry (php-src ext/intl/php_intl.c; issue #5774, #20630).
 *
 * Register under {@see standard}; advertise logical {@code intl} via
 * {@see getAdditionalExtensionNames()} when {@see IntlExtensionPolicy::advertisesExtension()}
 * (host Zend php-intl loaded — #22691, re-#11472). Grapheme / IDN / Normalizer / Locale /
 * formatters / Collator / MessageFormatter / Transliterator / ResourceBundle / IntlBreakIterator /
 * IntlChar / UConverter / Spoofchecker gate together — no phantom class_exists (#19670, #11768,
 * #17694, #19593, #19594, #6366, #6171, #6139, #6187, #6188, #20035, #20630, #22691).
 * JIT/AOT: {@see JitNumberFormatterFormat} / {@see NumberFormatterFormatJitHelper} (#28648).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!IntlExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['intl'];
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        require_once __DIR__.'/bootstrap_intlexception.php';
        if (IntlExtensionPolicy::advertisesLocale()) {
            BuiltinClasses::registerLocale($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesIntlDateFormatter()) {
            BuiltinClasses::registerIntlDateFormatter($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesIntlCalendar()) {
            BuiltinClasses::registerIntlCalendar($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesNumberFormatter()) {
            BuiltinClasses::registerNumberFormatter($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesCollator()) {
            BuiltinClasses::registerCollator($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesMessageFormatter()) {
            BuiltinClasses::registerMessageFormatter($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesIntlListFormatter()) {
            BuiltinClasses::registerIntlListFormatter($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesTransliterator()) {
            BuiltinClasses::registerTransliterator($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesResourceBundle()) {
            BuiltinClasses::registerResourceBundle($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesBreakIterator()) {
            BuiltinClasses::registerBreakIterator($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesBuiltins()) {
            BuiltinClasses::register($runtime->vmContext);
        }
        if (IntlExtensionPolicy::advertisesNormalizer()) {
            BuiltinClasses::registerNormalizer($runtime->vmContext);
        }
        // GRAPHEME_EXTR_* / IDNA_* / U_* / INTL_ICU_* when policy advertises (php-src php_intl.c).
        foreach (IntlConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            if (\is_string($value)) {
                $var->string($value);
            } else {
                $var->int((int) $value);
            }
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        $functions = [];
        if (IntlExtensionPolicy::advertisesLocale()) {
            $functions[] = new locale_get_default();
            $functions[] = new locale_set_default();
            $functions[] = new locale_get_primary_language();
            $functions[] = new locale_get_region();
            $functions[] = new locale_get_script();
            $functions[] = new locale_lookup();
            $functions[] = new locale_filter_matches();
            $functions[] = new locale_accept_from_http();
            $functions[] = new locale_canonicalize();
            $functions[] = new locale_parse();
            $functions[] = new locale_compose();
            $functions[] = new locale_get_keywords();
            $functions[] = new locale_get_display_language();
            $functions[] = new locale_get_display_name();
            $functions[] = new locale_get_display_region();
            $functions[] = new locale_get_display_script();
            $functions[] = new locale_get_display_variant();
            $functions[] = new locale_get_all_variants();
            if (IntlExtensionPolicy::advertisesLocaleRtlAndLikelySubtags()) {
                $functions[] = new locale_is_right_to_left();
                $functions[] = new locale_add_likely_subtags();
                $functions[] = new locale_minimize_subtags();
            }
            if (IntlExtensionPolicy::advertisesLocaleDisplayKeyword()) {
                $functions[] = new locale_get_display_keyword();
                $functions[] = new locale_get_display_keyword_value();
            }
        }
        $normalizer = IntlExtensionPolicy::advertisesNormalizer()
            ? [
                new normalizer_normalize(),
                new normalizer_is_normalized(),
                new normalizer_get_raw_decomposition(),
            ]
            : [];
        $idn = IntlExtensionPolicy::advertisesIdn()
            ? [new idn_to_ascii(), new idn_to_utf8()]
            : [];

        $collator = IntlExtensionPolicy::advertisesCollator()
            ? [
                new collator_create(),
                new collator_compare(),
                new collator_sort(),
                new collator_asort(),
                new collator_sort_with_sort_keys(),
                new collator_get_attribute(),
                new collator_set_attribute(),
                new collator_get_strength(),
                new collator_set_strength(),
                new collator_get_sort_key(),
                new collator_get_locale(),
                new collator_get_error_code(),
                new collator_get_error_message(),
            ]
            : [];

        $numfmt = IntlExtensionPolicy::advertisesNumberFormatter()
            ? [
                new numfmt_create(),
                new numfmt_format(),
                new numfmt_parse(),
                new numfmt_parse_currency(),
                new numfmt_format_currency(),
                new numfmt_get_attribute(),
                new numfmt_set_attribute(),
                new numfmt_get_symbol(),
                new numfmt_set_symbol(),
                new numfmt_get_text_attribute(),
                new numfmt_set_text_attribute(),
                new numfmt_get_pattern(),
                new numfmt_set_pattern(),
                new numfmt_get_locale(),
                new numfmt_get_error_code(),
                new numfmt_get_error_message(),
            ]
            : [];

        $msgfmt = IntlExtensionPolicy::advertisesMessageFormatter()
            ? [
                new msgfmt_create(),
                new msgfmt_format(),
                new msgfmt_format_message(),
                new msgfmt_parse(),
                new msgfmt_parse_message(),
                new msgfmt_get_locale(),
                new msgfmt_get_pattern(),
                new msgfmt_set_pattern(),
                new msgfmt_get_error_code(),
                new msgfmt_get_error_message(),
            ]
            : [];

        $transliterator = IntlExtensionPolicy::advertisesTransliterator()
            ? [
                new transliterator_create(),
                new transliterator_create_from_rules(),
                new transliterator_create_inverse(),
                new transliterator_list_ids(),
                new transliterator_transliterate(),
                new transliterator_get_error_code(),
                new transliterator_get_error_message(),
            ]
            : [];

        $resourcebundle = IntlExtensionPolicy::advertisesResourceBundle()
            ? [
                new resourcebundle_create(),
                new resourcebundle_get(),
                new resourcebundle_locales(),
                new resourcebundle_count(),
                new resourcebundle_get_error_code(),
                new resourcebundle_get_error_message(),
            ]
            : [];

        $datefmt = IntlExtensionPolicy::advertisesIntlDateFormatter()
            ? [
                new datefmt_create(),
                new datefmt_format(),
                new datefmt_format_object(),
                new datefmt_parse(),
                new datefmt_localtime(),
                new datefmt_get_error_code(),
                new datefmt_get_error_message(),
                new datefmt_get_pattern(),
                new datefmt_set_pattern(),
                new datefmt_get_timezone(),
                new datefmt_set_timezone(),
                new datefmt_get_locale(),
                new datefmt_get_datetype(),
                new datefmt_get_timetype(),
                new datefmt_is_lenient(),
                new datefmt_set_lenient(),
                new datefmt_get_calendar(),
                new datefmt_set_calendar(),
                new datefmt_get_timezone_id(),
                new datefmt_get_calendar_object(),
            ]
            : [];

        $intlcal = IntlExtensionPolicy::advertisesIntlCalendar()
            ? [
                new intlcal_create_instance(),
                new intlcal_get_now(),
                new intlcal_from_date_time(),
                new intlcal_get(),
                new intlcal_set(),
                new intlcal_get_type(),
                new intlcal_add(),
                new intlcal_roll(),
                new intlcal_clear(),
                new intlcal_is_set(),
                new intlcal_equals(),
                new intlcal_get_time(),
                new intlcal_set_time(),
                new intlcal_get_time_zone(),
                new intlcal_to_date_time(),
                new intlcal_field_difference(),
                new intlcal_before(),
                new intlcal_after(),
                new intlcal_set_time_zone(),
                new intlcal_get_minimum(),
                new intlcal_get_maximum(),
                new intlcal_get_available_locales(),
                // IntlCalendar weekend/bounds/wall-time/error procedurals (#20895)
                new intlcal_is_weekend(),
                new intlcal_get_actual_minimum(),
                new intlcal_get_actual_maximum(),
                new intlcal_get_least_maximum(),
                new intlcal_get_greatest_minimum(),
                new intlcal_get_day_of_week_type(),
                new intlcal_get_weekend_transition(),
                new intlcal_get_repeated_wall_time_option(),
                new intlcal_set_repeated_wall_time_option(),
                new intlcal_get_skipped_wall_time_option(),
                new intlcal_set_skipped_wall_time_option(),
                new intlcal_get_error_code(),
                new intlcal_get_error_message(),
                // IntlCalendar week/locale/daylight/lenient/keyword procedurals (#20896)
                new intlcal_get_locale(),
                new intlcal_is_lenient(),
                new intlcal_set_lenient(),
                new intlcal_in_daylight_time(),
                new intlcal_get_first_day_of_week(),
                new intlcal_set_first_day_of_week(),
                new intlcal_get_minimal_days_in_first_week(),
                new intlcal_set_minimal_days_in_first_week(),
                new intlcal_get_keyword_values_for_locale(),
                new intlcal_is_equivalent_to(),
                // IntlGregorianCalendar procedurals (php-src php_intl.stub.php; #20906)
                // createFromDate* are OO-only in php-src — no intlgregcal_create_from_date* (#26745)
                new intlgregcal_create_instance(),
                new intlgregcal_is_leap_year(),
                new intlgregcal_get_gregorian_change(),
                new intlgregcal_set_gregorian_change(),
                // IntlTimeZone procedural aliases (php-src timezone.stub.php @alias; #20859)
                new intltz_get_gmt(),
                new intltz_create_time_zone(),
                new intltz_create_default(),
                new intltz_get_id(),
                new intltz_get_display_name(),
                new intltz_get_raw_offset(),
                new intltz_get_dst_savings(),
                new intltz_from_date_time_zone(),
                new intltz_to_date_time_zone(),
                new intltz_get_canonical_id(),
                new intltz_get_region(),
                // IntlTimeZone procedurals after OOP (#20925; get_iana_id also #20926)
                // intltz_get_utc never existed in php-src — withhold (#26745)
                new intltz_count_equivalent_ids(),
                new intltz_get_equivalent_id(),
                new intltz_get_windows_id(),
                new intltz_get_id_for_windows_id(),
                new intltz_create_enumeration(),
                new intltz_create_time_zone_id_enumeration(),
                new intltz_get_unknown(),
                new intltz_get_tz_data_version(),
                new intltz_use_daylight_time(),
                new intltz_has_same_rules(),
                new intltz_get_error_code(),
                new intltz_get_error_message(),
                new intltz_get_offset(),
            ]
            : [];
        // php-src timezone.stub.php — intltz_get_iana_id when ICU ≥74 (#20926)
        if (IntlExtensionPolicy::advertisesIanaTimeZoneId()) {
            $intlcal[] = new intltz_get_iana_id();
        }

        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            return [
                ...$functions,
                ...$normalizer,
                ...$idn,
                ...$collator,
                ...$numfmt,
                ...$msgfmt,
                ...$transliterator,
                ...$resourcebundle,
                ...$datefmt,
                ...$intlcal,
                ...IntlExtensionPolicy::profileLocaleParserFunctions(),
            ];
        }

        return [
            ...$functions,
            ...$normalizer,
            ...$idn,
            ...$collator,
            ...$numfmt,
            ...$msgfmt,
            ...$transliterator,
            ...$resourcebundle,
            ...$datefmt,
            ...$intlcal,
            new grapheme_strlen(),
            new grapheme_substr(),
            new grapheme_strpos(),
            // PHP 8.4+ only — withhold on PROFILE=8.2 like str_split/strimwidth (#22564)
            ...(CompilerVersion::supportsGraphemeStrContains() ? [new grapheme_str_contains()] : []),
            new grapheme_strstr(),
            new grapheme_stristr(),
            new grapheme_stripos(),
            new grapheme_strrpos(),
            new grapheme_strripos(),
            new grapheme_extract(),
            // Never shipped by Zend — withhold on every profile (#22661 / #6998).
            ...(CompilerVersion::supportsGraphemeLevenshtein() ? [new grapheme_levenshtein()] : []),
            ...(CompilerVersion::supportsGraphemeStrSplit() ? [new grapheme_str_split()] : []),
            ...(CompilerVersion::supportsGraphemeStrimwidth() ? [new grapheme_strimwidth()] : []),
            new intl_get_error_code(),
            new intl_get_error_message(),
            new intl_is_failure(),
            new intl_error_name(),
        ];
    }
}
