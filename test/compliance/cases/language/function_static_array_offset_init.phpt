--TEST--
Language: function static array subscript constant initializer (#12025, zend_compile.c)
--FILE--
<?php
function f_list(): void
{
    static $x = [1, 2][0];
    echo $x, "\n";
}
function f_assoc(): void
{
    static $x = ['a' => 1]['a'];
    echo $x, "\n";
}
function f_add(): void
{
    static $x = 1 + 2;
    echo $x, "\n";
}
f_list();
f_assoc();
f_add();
--EXPECT--
1
1
3
