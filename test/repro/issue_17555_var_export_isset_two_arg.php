<?php
class T {
    public int $i;
    public static int $s;
}
$t = new T();
var_export(isset($t->i), true);
echo "\n";
var_export(empty($t->i), true);
echo "\n";
var_export(isset(T::$s), true);
echo "\n";
var_export(empty(T::$s), true);
echo "\n";
