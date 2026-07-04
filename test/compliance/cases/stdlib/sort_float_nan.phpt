--TEST--
stdlib sort()/rsort() float arrays with NAN — php-src sort order (#10144, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$b = [3.0, NAN, 1.0];
sort($b, SORT_REGULAR);
var_export($b);
echo "\n";
$c = [3.0, NAN, 1.0];
rsort($c, SORT_REGULAR);
var_export($c);
echo "\n";
--EXPECT--
array (
  0 => NAN,
  1 => 1.0,
  2 => 3.0,
)
array (
  0 => 3.0,
  1 => NAN,
  2 => 1.0,
)
