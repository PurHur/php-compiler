--TEST--
Language: function static array subscript constant initializer (#12025, zend_compile_static_variable)
--FILE--
<?php
function f_list() {
    static $x = [1, 2][0];
    return $x;
}
function f_assoc() {
    static $x = ['a' => 1]['a'];
    return $x;
}
echo f_list(), "\n", f_assoc(), "\n";
--EXPECT--
1
1
