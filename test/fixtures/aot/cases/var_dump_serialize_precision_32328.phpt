--TEST--
AOT: var_dump(float) uses serialize_precision %.*H not echo precision (#32328)
--FILE--
<?php
echo 1 / 3, "\n";
var_dump(0.1);
var_dump(1 / 3);
var_dump(0.1 + 0.2);
var_dump(PHP_INT_MAX + 1);
var_dump(INF);
var_dump(NAN);
--EXPECT--
0.33333333333333
float(0.1)
float(0.3333333333333333)
float(0.30000000000000004)
float(9.223372036854776E+18)
float(INF)
float(NAN)
--EXPECT_EXIT--
0
