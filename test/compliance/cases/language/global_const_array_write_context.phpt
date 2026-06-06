--TEST--
Language: global const array element assign — compile-time fatal (#6935)
--FILE--
<?php
const X = [1];
X[0] = 2;
var_dump(X);
--EXPECT_EXIT--
255
