--TEST--
locale_filter_matches() JIT prefix filter matches php-src/VM (#32119)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale filterMatches withheld until extension_loaded(\'intl\') (#19670/#32119)';
}
?>
--FILE--
<?php
echo 'filter_pos=', locale_filter_matches('de-DE', 'de', false) ? 'true' : 'false', "\n";
echo 'filter_named=', locale_filter_matches(
    languageTag: 'de-DE',
    locale: 'de',
    canonicalize: true
) ? 'true' : 'false', "\n";
$tag = 'de-DE';
$locale = 'de';
echo 'filter_vars=', locale_filter_matches($tag, $locale, true) ? 'true' : 'false', "\n";
echo 'filter_omit=', locale_filter_matches('de-DE', 'de') ? 'true' : 'false', "\n";
echo 'kw_f=', (int) locale_filter_matches('en_US@currency=usd', 'en_US', false), "\n";
echo 'kw_t=', (int) locale_filter_matches('en_US@currency=usd', 'en_US', true), "\n";
echo 'kling_f=', (int) locale_filter_matches('i-klingon', 'tlh', false), "\n";
echo 'kling_t=', (int) locale_filter_matches('i-klingon', 'tlh', true), "\n";
try {
    locale_filter_matches(langtag: 'de-DE', locale: 'de');
    echo "legacy_filter_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
filter_pos=true
filter_named=true
filter_vars=true
filter_omit=true
kw_f=0
kw_t=1
kling_f=0
kling_t=1
Unknown named parameter $langtag
