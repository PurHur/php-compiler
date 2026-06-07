--TEST--
Language: global const array unset — compile-time fatal (#6935)
--FILE--
<?php
const X = [1, 2];
unset(X[0]);
var_dump(X);
--EXPECT_EXIT--
255
