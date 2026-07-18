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
            ? [new collator_create()]
            : [];

        $msgfmt = IntlExtensionPolicy::advertisesMessageFormatter()
            ? [
                new msgfmt_create(),
                new msgfmt_format(),
                new msgfmt_format_message(),
            ]
            : [];

        $transliterator = IntlExtensionPolicy::advertisesTransliterator()
            ? [
                new transliterator_create(),
                new transliterator_transliterate(),
            ]
            : [];

        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            return [
                ...$functions,
                ...$normalizer,
                ...$idn,
                ...$collator,
                ...$msgfmt,
                ...$transliterator,
                ...IntlExtensionPolicy::profileLocaleParserFunctions(),
            ];
        }

        return [
            ...$functions,
            ...$normalizer,
            ...$idn,
            ...$collator,
            ...$msgfmt,
            ...$transliterator,
            new grapheme_strlen(),
            new grapheme_substr(),
            new grapheme_strpos(),
            new grapheme_str_contains(),
            new grapheme_strstr(),
            new grapheme_stristr(),
            new grapheme_stripos(),
            new grapheme_strrpos(),
            new grapheme_extract(),
            new grapheme_levenshtein(),
            new grapheme_str_split(),
            ...(CompilerVersion::supportsGraphemeStrimwidth() ? [new grapheme_strimwidth()] : []),
            new intl_get_error_code(),
            new intl_get_error_message(),
            new intl_is_failure(),
        ];
    }
}
