--TEST--
stdlib get_browser() browscap lookup (#3286, ext/standard/browscap.c)
--INI--
browscap={PWD}/../../fixtures/browscap_mini.ini
--FILE--
<?php
$ua = 'Opera/7.1 (Windows NT 5.1; U) en';
$arr = get_browser($ua, true);
var_dump(is_array($arr));
echo ($arr['browser'] ?? 'missing'), "\n";
echo ($arr['browser_name_pattern'] ?? 'missing'), "\n";
--EXPECT--
bool(true)
Opera
Opera/7.1* (Windows NT 5.1; ?)*
