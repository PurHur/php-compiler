--TEST--
Language: var_export(isset()) on uninitialized typed property exports bool (#17555, ext/standard/var.c)
--FILE--
<?php
class T {
    public int $i;
}
$t = new T();
echo var_export(isset($t->i), true), "\n";
echo var_export(empty($t->i), true), "\n";
--EXPECT--
false
true
