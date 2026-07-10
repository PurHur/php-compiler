--TEST--
Language: var_export(isset()/empty()) on typed instance property exports bool (#17555, ext/standard/var.c)
--FILE--
<?php
class T {
    public int $i;
    public static int $s;
}
$t = new T();
echo var_export(isset($t->i), true), ' ', var_export(empty($t->i), true), "\n";
echo var_export(isset(T::$s), true), ' ', var_export(empty(T::$s), true), "\n";
--EXPECT--
false true
false true
