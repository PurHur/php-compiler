--TEST--
stdlib fdiv() — IEEE float division (PHP 8.0)
--FILE--
<?php
echo fdiv(5, 2), "\n";
echo fdiv(5.0, 2.0), "\n";
echo is_infinite(fdiv(1.0, 0.0)) ? "inf\n" : "no\n";
echo is_nan(fdiv(0.0, 0.0)) ? "nan\n" : "no\n";
--EXPECT--
2.5
2.5
inf
nan
