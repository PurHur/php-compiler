--TEST--
Language: function static array dim/append/unset persist across calls (#28038, Zend/zend_execute.c)
--FILE--
<?php
function f_append(): string
{
    static $x = [1, 2, 3];
    $x[] = 4;
    return implode(',', $x);
}
function f_unset(): string
{
    static $x = ['a' => 1, 'b' => 2];
    unset($x['a']);
    return json_encode($x);
}
function f_shift(): string
{
    static $x = [1, 2, 3];
    $v = array_shift($x);
    return $v . ':' . implode(',', $x);
}
function f_keyed(): string
{
    static $x = ['a' => 1, 'b' => 2];
    $x['c'] = 3;
    return json_encode($x);
}
function f_readonly(): string
{
    static $x = [1, 2, 3];
    return implode(',', $x);
}
echo f_append(), "\n", f_append(), "\n";
echo f_unset(), "\n", f_unset(), "\n";
echo f_shift(), "\n", f_shift(), "\n";
echo f_keyed(), "\n", f_keyed(), "\n";
echo f_readonly(), "\n", f_readonly(), "\n";
--EXPECT--
1,2,3,4
1,2,3,4,4
{"b":2}
{"b":2}
1:2,3
2:3
{"a":1,"b":2,"c":3}
{"a":1,"b":2,"c":3}
1,2,3
1,2,3
