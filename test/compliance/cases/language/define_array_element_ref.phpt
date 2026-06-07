--TEST--
Language: define() array element assign-by-reference — write-context fatal (#5409)
--FILE--
<?php
define('A', [1]);
$a = &A[0];
$a = 2;
var_dump(A);
--EXPECT_EXIT--
255
