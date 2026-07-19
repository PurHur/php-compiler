--TEST--
Locale canonicalize/parseLocale/composeLocale/getKeywords (#20738)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale BCP-47 helpers withheld until extension_loaded(\'intl\') (#19670/#20738)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('Locale');
foreach (['canonicalize', 'parseLocale', 'composeLocale', 'getKeywords'] as $n) {
    echo $n, '=', $r->hasMethod($n) ? '1' : '0', "\n";
}
echo 'canon=', Locale::canonicalize('en-US'), "\n";
echo 'canon_case=', Locale::canonicalize('EN-us'), "\n";
$parsed = Locale::parseLocale('en_US_POSIX');
echo 'parse_lang=', $parsed['language'] ?? '', ' region=', $parsed['region'] ?? '', ' v0=', $parsed['variant0'] ?? '', "\n";
echo 'compose=', Locale::composeLocale(['language' => 'zh', 'script' => 'Hans', 'region' => 'CN']), "\n";
$kw = Locale::getKeywords('de_DE@currency=EUR;collation=phonebook');
echo 'kw_currency=', $kw['currency'] ?? '', ' collation=', $kw['collation'] ?? '', "\n";
echo 'kw_none=', var_export(Locale::getKeywords('en_US'), true), "\n";
?>
--EXPECT--
canonicalize=1
parseLocale=1
composeLocale=1
getKeywords=1
canon=en_US
canon_case=en_US
parse_lang=en region=US v0=POSIX
compose=zh_Hans_CN
kw_currency=EUR collation=phonebook
kw_none=NULL
