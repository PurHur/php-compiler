--TEST--
AOT: unary ~ on string links __string__bitwiseNot (#35301, zend_operators.c bitwise_not_function)
--FILE--
<?php
function s(string $x): string
{
    return $x;
}
echo bin2hex(~'a'), PHP_EOL;
echo bin2hex(~s('a')), PHP_EOL;
echo bin2hex(~'5'), PHP_EOL;
var_dump(~'a');
--EXPECT--
9e
9e
ca
string(1) "�"
--EXPECT_EXIT--
0
