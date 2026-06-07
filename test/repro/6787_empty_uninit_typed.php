<?php
class T {
    public int $i;
    public static int $s;
}
$t = new T();
echo 'isset i=', var_export(isset($t->i), true), ' empty i=', var_export(empty($t->i), true), PHP_EOL;
echo 'isset s=', var_export(isset(T::$s), true), ' empty s=', var_export(empty(T::$s), true), PHP_EOL;
