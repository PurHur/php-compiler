--TEST--
stdlib number_format() NAN/INF/-INF (issue #4680, ext/standard/math.c)
--FILE--
<?php
echo number_format(NAN, 2), "\n";
echo number_format(INF, 2), "\n";
echo number_format(-INF, 2), "\n";
echo number_format(NAN), "\n";
echo number_format(INF, 0, '|', ' '), "\n";
--EXPECT--
nan
inf
inf
nan
inf
