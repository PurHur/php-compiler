--TEST--
stdlib DateTimeZone::listIdentifiers() — OOP timezone list (#6198, ext/date/php_datetime.c)
--FILE--
<?php
echo method_exists('DateTimeZone', 'listIdentifiers') ? "oop=yes\n" : "oop=no\n";
$all = DateTimeZone::listIdentifiers();
echo count($all) > 0 ? "non_empty\n" : "empty\n";
echo in_array('UTC', $all, true) ? "has_utc\n" : "no_utc\n";
echo $all[0], "\n";
$africa = DateTimeZone::listIdentifiers(DateTimeZone::AFRICA);
echo count($africa) > 0 ? "africa_ok\n" : "africa_empty\n";
echo $africa[0], "\n";
$us = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
echo count($us), "\n";
if (count($us) > 0) {
    echo $us[0], "\n";
}
$proc = timezone_identifiers_list();
echo count($proc) === count($all) ? "proc_sync\n" : "proc_mismatch\n";
--EXPECT--
oop=yes
non_empty
has_utc
Africa/Abidjan
africa_ok
Africa/Abidjan
29
America/Adak
proc_sync
