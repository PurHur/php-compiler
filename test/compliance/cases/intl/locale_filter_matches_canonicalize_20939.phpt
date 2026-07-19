--TEST--
Locale::filterMatches canonicalize keyword separator (#20939 / php-src locale_methods.c)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale negotiate withheld until extension_loaded(\'intl\') (#19670/#20036)';
}
?>
--FILE--
<?php
// php-src: canonicalize=false rejects `@` after a prefix match; canonicalize=true accepts it.
$cases = [
    ['en_US@currency=usd', 'en_US', false],
    ['en_US@currency=usd', 'en_US', true],
    ['de-de@collation=phonebook', 'de_DE', false],
    ['de-de@collation=phonebook', 'de_DE', true],
    ['en-us', 'en_US', false],
    ['en-us', 'en_US', true],
    ['en_US_POSIX', 'en_US', false],
    ['en', 'en_US', false],
    ['en_US', '*', false],
];
foreach ($cases as [$lang, $range, $canon]) {
    $oop = Locale::filterMatches($lang, $range, $canon);
    $proc = locale_filter_matches($lang, $range, $canon);
    echo $lang, ' / ', $range, ' / ', $canon ? 'T' : 'F', ' => ',
        $oop ? 'true' : 'false',
        $oop === $proc ? '' : ' MISMATCH_PROC',
        "\n";
}
?>
--EXPECT--
en_US@currency=usd / en_US / F => false
en_US@currency=usd / en_US / T => true
de-de@collation=phonebook / de_DE / F => false
de-de@collation=phonebook / de_DE / T => true
en-us / en_US / F => true
en-us / en_US / T => true
en_US_POSIX / en_US / F => true
en / en_US / F => false
en_US / * / F => true
