--TEST--
locale_lookup() JIT RFC 4647 lookup matches php-src/VM (#32118)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale lookup withheld until extension_loaded(\'intl\') (#19670/#32118)';
}
?>
--FILE--
<?php
echo 'lookup_pos=', locale_lookup(['de-DE', 'de'], 'de-CH', true, 'en'), "\n";
echo 'lookup_named=', locale_lookup(
    languageTag: ['de-DE', 'de'],
    locale: 'de-CH',
    canonicalize: true,
    defaultLocale: 'en'
), "\n";
$tags = ['de-DE', 'de'];
$locale = 'de-CH';
echo 'lookup_vars=', locale_lookup($tags, $locale, true, 'en'), "\n";
echo 'lookup_fallback=', locale_lookup(['de-DE', 'fr-FR'], 'de-CH', false, 'en_US'), "\n";
echo 'lookup_omit=', locale_lookup(['de-DEDE', 'de-DE', 'de', 'fr'], 'de-DE-1996'), "\n";
try {
    locale_lookup(langtag: ['de-DE'], locale: 'de', default: 'en');
    echo "legacy_lookup_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
lookup_pos=de
lookup_named=de
lookup_vars=de
lookup_fallback=en_US
lookup_omit=de-DE
Unknown named parameter $langtag
