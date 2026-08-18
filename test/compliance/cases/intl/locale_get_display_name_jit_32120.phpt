--TEST--
locale_get_display_name() JIT matches php-src/VM display string (#32120)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale display helpers withheld until extension_loaded(\'intl\') (#19670/#32120)';
}
?>
--FILE--
<?php
echo 'proc=', locale_get_display_name('de_DE', 'en'), "\n";
echo 'named=', locale_get_display_name(locale: 'de_DE', in_locale: 'en'), "\n";
$locale = 'de_DE';
$display = 'en';
echo 'vars=', locale_get_display_name($locale, $display), "\n";
echo 'oop=', Locale::getDisplayName('de_DE', 'en'), "\n";
echo 'match=', (int) (locale_get_display_name('de_DE', 'en') === Locale::getDisplayName('de_DE', 'en')), "\n";
?>
--EXPECT--
proc=German (Germany)
named=German (Germany)
vars=German (Germany)
oop=German (Germany)
match=1
