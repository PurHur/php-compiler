--TEST--
locale_lookup()/locale_filter_matches() Reflection languageTag/defaultLocale + named args (#25198)
--SKIPIF--
<?php
if (!extension_loaded('intl') || !function_exists('locale_lookup') || !function_exists('locale_filter_matches')) {
    die('skip host php-intl / locale_* required');
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach (['locale_lookup', 'locale_filter_matches'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, " arity=", $rf->getNumberOfParameters(), " req=", $rf->getNumberOfRequiredParameters(), "\n";
    echo $fn, " ret=", $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
        if ($p->isOptional()) {
            echo ' OPT';
            if ($p->isDefaultValueAvailable()) {
                echo '=', json_encode($p->getDefaultValue());
            }
        } else {
            echo ' REQ';
        }
        echo "\n";
    }
}

echo 'lookup_pos=', locale_lookup(['de-DE', 'de'], 'de-CH', true, 'en'), "\n";
try {
    echo 'lookup_named=', locale_lookup(
        languageTag: ['de-DE', 'de'],
        locale: 'de-CH',
        canonicalize: true,
        defaultLocale: 'en'
    ), "\n";
} catch (Throwable $e) {
    echo 'lookup_named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    locale_lookup(langtag: ['de-DE'], locale: 'de', default: 'en');
    echo "legacy_lookup accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

echo 'filter_pos=', var_export(locale_filter_matches('de-DE', 'de', false), true), "\n";
try {
    echo 'filter_named=', var_export(
        locale_filter_matches(languageTag: 'de-DE', locale: 'de', canonicalize: false),
        true
    ), "\n";
} catch (Throwable $e) {
    echo 'filter_named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    locale_filter_matches(langtag: 'de-DE', locale: 'de');
    echo "legacy_filter accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
locale_lookup arity=4 req=2
locale_lookup ret=?string
  array $languageTag REQ
  string $locale REQ
  bool $canonicalize OPT=false
  ?string $defaultLocale OPT=null
locale_filter_matches arity=3 req=2
locale_filter_matches ret=?bool
  string $languageTag REQ
  string $locale REQ
  bool $canonicalize OPT=false
lookup_pos=de
lookup_named=de
Unknown named parameter $langtag
filter_pos=true
filter_named=true
Unknown named parameter $langtag
