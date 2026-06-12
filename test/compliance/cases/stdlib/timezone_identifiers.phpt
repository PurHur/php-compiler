--TEST--
stdlib timezone_identifiers_list() — Olson identifiers (#3504)
--FILE--
<?php
echo function_exists('timezone_identifiers_list') ? "fn_ok\n" : "fn_missing\n";
$all = timezone_identifiers_list();
echo count($all) > 0 ? "non_empty\n" : "empty\n";
echo in_array('UTC', $all, true) ? "has_utc\n" : "no_utc\n";
echo $all[0], "\n";
$africa = timezone_identifiers_list(DateTimeZone::AFRICA);
echo count($africa) > 0 ? "africa_ok\n" : "africa_empty\n";
echo $africa[0], "\n";
$us = timezone_identifiers_list(DateTimeZone::PER_COUNTRY, 'US');
echo count($us), "\n";
if (count($us) > 0) {
    echo $us[0], "\n";
}
--EXPECT--
fn_ok
non_empty
has_utc
Africa/Abidjan
africa_ok
Africa/Abidjan
29
America/Adak
