--TEST--
stdlib DateTimeZone::listAbbreviations() — abbreviation map (#11874, ext/date/php_date.c)
--FILE--
<?php
echo function_exists('timezone_abbreviations_list') ? "procedural=yes\n" : "procedural=no\n";
echo method_exists('DateTimeZone', 'listAbbreviations') ? "oop=yes\n" : "oop=no\n";
$list = DateTimeZone::listAbbreviations();
echo 'count=', count($list), "\n";
echo 'has_est=', isset($list['est']) ? 'yes' : 'no', "\n";
$est = $list['est'][0];
echo 'est_offset=', $est['offset'], "\n";
echo 'est_dst=', $est['dst'] ? 'true' : 'false', "\n";
echo 'est_tz=', $est['timezone_id'], "\n";
--EXPECT--
procedural=yes
oop=yes
count=144
has_est=yes
est_offset=-18000
est_dst=false
est_tz=America/New_York
