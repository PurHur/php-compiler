--TEST--
Language: overflow numeric-string ⊙ int is float (IS_DOUBLE) on AOT/JIT (#32432)
--FILE--
<?php
var_dump("9223372036854775808" + 0);
var_dump(0 + "9223372036854775808");
var_dump("9223372036854775808" + "0");
var_dump("10" + 3);
?>
--EXPECT--
float(9.223372036854776E+18)
float(9.223372036854776E+18)
float(9.223372036854776E+18)
int(13)
