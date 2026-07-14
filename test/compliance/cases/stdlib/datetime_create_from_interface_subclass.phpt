--TEST--
stdlib DateTime::createFromInterface() DateTime subclass operand (#18921, ext/date/php_date.c)
--FILE--
<?php
class MyDate extends DateTime
{
}

$d = new MyDate('2020-01-01');
$c = DateTime::createFromInterface($d);
echo $c->format('Y-m-d'), "\n";
var_export($c instanceof DateTime);
echo "\n";
var_export($d instanceof MyDate);
echo "\n";
--EXPECT--
2020-01-01
true
true
