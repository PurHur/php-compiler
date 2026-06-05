--TEST--
stdlib fpow() — IEEE float power (PHP 8.4, ext/standard/math.c)
--FILE--
<?php
echo fpow(2, 3), "\n";
echo fpow(4, 0.5), "\n";
echo fpow(10, 0), "\n";
echo is_nan(fpow(-1, 0.5)) ? "nan\n" : "no\n";
--EXPECT--
8
2
1
nan
