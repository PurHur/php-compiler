--TEST--
stdlib fpow() — IEEE edge cases (PHP 8.4, ext/standard/math.c, #7045)
--FILE--
<?php
echo is_infinite(fpow(0, -1)) ? "inf\n" : "no\n";
echo is_nan(fpow(-1, 0.5)) ? "nan\n" : "no\n";
echo fpow(-2, 2), "\n";
echo fpow(10, 0), "\n";
--EXPECT--
inf
nan
4
1
