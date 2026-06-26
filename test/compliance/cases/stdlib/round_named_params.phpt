--TEST--
stdlib round() num:/precision:/mode: named parameters (#12103, ext/standard/math.c)
--FILE--
<?php
echo round(num: 2.5), "\n";
echo round(2.5, precision: 0, mode: PHP_ROUND_HALF_ODD), "\n";
echo round(2.5), "\n";
--EXPECT--
3
3
3
