--TEST--
AOT: fpow() IEEE float power (#5998, ext/standard/math.c)
--FILE--
<?php
echo fpow(2, 3), "\n";
echo fpow(4, 0.5), "\n";
echo fpow(10, 0), "\n";
echo fpow(9, 0.5), "\n";
--EXPECT--
8
2
1
3
--EXPECT_EXIT--
0
