--TEST--
AOT: fmin()/fmax() variadic floats (issue #11728)
--FILE--
<?php
echo function_exists('fmin') ? '1' : '0', "\n";
echo fmin(1.5, 2.0, 0.5), "\n";
echo fmax(1.5, 2.0, 3.0), "\n";
?>
--EXPECT--
1
0.5
3
