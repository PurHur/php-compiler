--TEST--
date (array) cast DateInterval Zend wire no fatal (#22425, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DateInterval('P1Y2M3DT4H5M6S');
var_export((array) $d);
echo "\n";
$f = DateInterval::createFromDateString('1 day');
var_export((array) $f);
echo "\n";
?>
--EXPECT--
array (
  'y' => 1,
  'm' => 2,
  'd' => 3,
  'h' => 4,
  'i' => 5,
  's' => 6,
  'f' => 0.0,
  'invert' => 0,
  'days' => false,
  'from_string' => false,
)
array (
  'from_string' => true,
  'date_string' => '1 day',
)
