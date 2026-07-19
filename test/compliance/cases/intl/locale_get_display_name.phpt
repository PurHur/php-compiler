--TEST--
locale_get_display_name() procedural alias of Locale::getDisplayName (#20835)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale display helpers withheld until extension_loaded(\'intl\') (#19670/#20835)';
}
?>
--FILE--
<?php
echo 'exists=', (int) function_exists('locale_get_display_name'), "\n";
$oop = Locale::getDisplayName('de_DE', 'en');
$proc = locale_get_display_name('de_DE', 'en');
echo 'oop=', $oop, "\n";
echo 'proc=', $proc, "\n";
echo 'match=', (int) ($oop === $proc), "\n";
echo 'lang=', locale_get_display_language('de_DE', 'en'), "\n";
?>
--EXPECT--
exists=1
oop=German (Germany)
proc=German (Germany)
match=1
lang=German
