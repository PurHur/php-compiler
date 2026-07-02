--TEST--
Language: isset() on uninitialized static typed property returns false (#15112, zend_compile.c)
--FILE--
<?php
class T {
    public static int $s;
}
var_export(isset(T::$s));
echo "\n";
var_export(empty(T::$s));
echo "\n";
--EXPECT--
false
true
