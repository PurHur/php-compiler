--TEST--
stdlib spl_autoload_register() — default spl_autoload when callback omitted (#4256)
--FILE--
<?php
var_dump(spl_autoload_register());
$funcs = spl_autoload_functions();
var_dump($funcs);
var_dump(in_array('spl_autoload', $funcs, true));
--EXPECT--
bool(true)
array(1) {
  [0]=>
  string(12) "spl_autoload"
}
bool(true)
