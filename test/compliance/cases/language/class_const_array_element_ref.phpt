--TEST--
Language: class const array element assign-by-reference — write-context fatal (#5409)
--FILE--
<?php
class C {
    public const A = [1];
}
$a = &C::A[0];
$a = 9;
var_dump(C::A);
--EXPECT_EXIT--
255
