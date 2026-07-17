--TEST--
Locale::lookup / filterMatches / acceptFromHttp (#20036)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale negotiate withheld until extension_loaded(\'intl\') (#19670/#20036)';
}
?>
--FILE--
<?php
echo 'lookup=', Locale::lookup(['de-DEDE', 'de-DE', 'de', 'fr'], 'de-DE-1996'), "\n";
echo 'fallback=', Locale::lookup(['de-DE', 'fr-FR'], 'de-CH', false, 'en_US'), "\n";
echo 'filter=', (int) Locale::filterMatches('de-DE', 'de'), "\n";
echo 'accept=', Locale::acceptFromHttp('en-US,en;q=0.9,fr;q=0.8'), "\n";
?>
--EXPECT--
lookup=de-DE
fallback=en_US
filter=1
accept=en_US
