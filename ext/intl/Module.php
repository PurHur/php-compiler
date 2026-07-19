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
 * (ICU libs present — curl/bz2 pattern). Grapheme / IDN / Normalizer / Locale / formatters /
 * Collator / MessageFormatter / Transliterator / ResourceBundle / IntlBreakIterator / IntlChar /
 * UConverter / Spoofchecker gate together — no phantom class_exists (#19670, #11768, #17694,
 * #19593, #19594, #6366, #6171, #6139, #6187, #6188, #20035, #20630).
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
        foreach (IntlConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
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
            ]
            : [];

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
            new grapheme_str_contains(),
            new grapheme_strstr(),
            new grapheme_stristr(),
            new grapheme_stripos(),
            new grapheme_strrpos(),
            new grapheme_strripos(),
            new grapheme_extract(),
            new grapheme_levenshtein(),
            new grapheme_str_split(),
            ...(CompilerVersion::supportsGraphemeStrimwidth() ? [new grapheme_strimwidth()] : []),
            new intl_get_error_code(),
            new intl_get_error_message(),
            new intl_is_failure(),
            new intl_error_name(),
        ];
    }
}
