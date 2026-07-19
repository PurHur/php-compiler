--TEST--
Locale::filterMatches honors $canonicalize (#20939 / php-src locale_methods)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale filterMatches withheld until extension_loaded(\'intl\')';
}
?>
--FILE--
<?php
declare(strict_types=1);

// Keyword separator: only accepted after canonicalize (php-src)
echo 'kw_f=', (int) Locale::filterMatches('en_US@currency=usd', 'en_US', false), "\n";
echo 'kw_t=', (int) Locale::filterMatches('en_US@currency=usd', 'en_US', true), "\n";
echo 'kw_en=', (int) Locale::filterMatches('en_US@currency=usd', 'en', false), "\n";

// Grandfathered alias needs canonicalize
echo 'kling_f=', (int) Locale::filterMatches('i-klingon', 'tlh', false), "\n";
echo 'kling_t=', (int) Locale::filterMatches('i-klingon', 'tlh', true), "\n";

// Unchanged non-canonical prefix
echo 'de=', (int) Locale::filterMatches('de-DE', 'de', false), "\n";
echo 'proc=', (int) locale_filter_matches('de-DE', 'de', true), "\n";
?>
--EXPECT--
kw_f=0
kw_t=1
kw_en=1
kling_f=0
kling_t=1
de=1
proc=1
