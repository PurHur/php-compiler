--TEST--
stdlib unserialize() — unserialize_callback_func invokes before __PHP_Incomplete_Class (#6564, var.c)
--FILE--
<?php
ini_set('unserialize_callback_func', 'class_exists');
$s = 'O:7:"Missing":0:{}';
$o = unserialize($s);
var_export($o);
echo "\n";
var_export(class_exists('Missing', false));
echo "\n";
var_export(get_class($o));
echo "\n";
--EXPECT--
PHP Warning:  unserialize(): Function class_exists() hasn't defined the class it was called for
__PHP_Incomplete_Class::__set_state(array (
  '__PHP_Incomplete_Class_Name' => 'Missing',
))
false
'__PHP_Incomplete_Class'
