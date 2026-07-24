--TEST--
stdlib strptime() — E_DEPRECATED on call (#22771, ext/date/php_date.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$r = @strptime('2020-01-02', '%Y-%m-%d');
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Function strptime() is deprecated') ? 'strptime_ok' : 'strptime_fail';
echo "\n";
echo $r['tm_mday'], ' ', $r['tm_mon'], ' ', $r['tm_year'], "\n";
?>
--EXPECT--
8192
strptime_ok
2 0 120
