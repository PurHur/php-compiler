<?php
/**
 * var_export($arr['key']->prop, true) must export the property value, not the object (#31938).
 *
 * php-src: ext/standard/var.c php_var_export; Zend/zend_execute.c FETCH_DIM_R + FETCH_OBJ_R
 * Compiler: lib/Compiler.php chained dim-fetch + property-fetch call-arg slot
 */
error_reporting(E_ALL);

class Simple
{
    public $name = 'test';
}

$obj = new Simple();
echo 'direct=', var_export($obj->name, true), "\n";

$arr = ['o' => new Simple()];
echo 'chained=', var_export($arr['o']->name, true), "\n";
