--TEST--
Language: string⊙string bitwise is byte-wise (#32431, Zend/zend_operators.c bitwise_*_function)
--FILE--
<?php
function s(string $x): string
{
    return $x;
}
echo ord(s('a') ^ s('b')), PHP_EOL;
var_dump(s('AB') & s('A'));
var_dump(s('A') | s('BC'));
echo ord(s('AB') ^ s('C')), PHP_EOL;
var_dump(s('') | s('x'));
var_dump(s('xy') & s(''));
var_dump(s('7') & s('3'));
?>
--EXPECT--
3
string(1) "A"
string(2) "CC"
2
string(1) "x"
string(0) ""
string(1) "3"
