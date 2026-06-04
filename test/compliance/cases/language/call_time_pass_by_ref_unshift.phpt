--TEST--
Language: call-time pass-by-reference in array_unshift must not compile (#5354)
--FILE--
<?php
$a = [1, 2];
array_unshift($a, &$a[0]);
$a[0] = 99;
var_export($a);
--EXPECT_EXIT--
255
