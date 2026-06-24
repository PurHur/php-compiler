--TEST--
stdlib date_parse() — unparsed time fields are false not 0 (#11068, ext/standard/parsedate.c)
--FILE--
<?php
$p = date_parse('2024-01-01');
var_export($p['hour']);
echo "\n";
var_export($p['minute']);
echo "\n";
var_export($p['second']);
echo "\n";
var_export($p['fraction']);
echo "\n";

$p2 = date_parse('2024-01-01 12:30:45');
echo (string) $p2['hour'], "\n";
echo (string) $p2['minute'], "\n";
echo (string) $p2['second'], "\n";
?>
--EXPECT--
false
false
false
false
12
30
45
