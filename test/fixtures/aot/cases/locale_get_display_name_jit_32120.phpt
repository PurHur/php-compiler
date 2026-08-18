--TEST--
locale_get_display_name() AOT display string (#32120)
--SKIPIF--
<?php
if (!extension_loaded('intl')) {
    die('skip intl required');
}
?>
--FILE--
<?php
echo locale_get_display_name('de_DE', 'en'), "\n";
$locale = 'de_DE';
echo locale_get_display_name($locale, 'en'), "\n";
--EXPECT--
German (Germany)
German (Germany)
