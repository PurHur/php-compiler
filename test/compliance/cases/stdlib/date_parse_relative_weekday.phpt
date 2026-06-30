--TEST--
stdlib date_parse() relative weekday strings — false calendar fields + relative.weekday (#14163, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$p = date_parse('next monday');
var_export($p['year']);
echo "\n";
var_export($p['month']);
echo "\n";
var_export($p['day']);
echo "\n";
echo (string) $p['relative']['weekday'], "\n";
echo (string) $p['relative']['day'], "\n";

$p2 = date_parse('last monday');
echo (string) $p2['relative']['weekday'], "\n";
echo (string) $p2['relative']['day'], "\n";
?>
--EXPECT--
false
false
false
1
0
1
-7
