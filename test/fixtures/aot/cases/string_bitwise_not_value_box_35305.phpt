--TEST--
AOT: unary ~ on boxed string local is byte-wise (#35305)
--FILE--
<?php
$s = 'a';
echo bin2hex(~$s), PHP_EOL;
$t = 'ab';
echo bin2hex(~$t), PHP_EOL;
var_dump(~$s);
$n = 5;
var_dump(~$n);
?>
--EXPECT--
9e
9e9d
string(1) "�"
int(-6)
