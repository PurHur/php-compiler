--TEST--
stdlib DateTime::createFromFormat() date-only unparsed time from now (#16383, ext/date/php_date.c)
--FILE--
<?php
$dt = DateTime::createFromFormat('Y-m-d', '2020-02-30');
echo $dt->format('Y-m-d'), "\n";
echo ($dt->format('H:i:s') === date('H:i:s') ? 'time_ok' : 'time_fail'), "\n";
$partial = DateTime::createFromFormat('Y-m-d H', '2020-01-01 14');
echo $partial->format('H:i:s'), "\n";
?>
--EXPECT--
2020-03-01
time_ok
14:00:00
