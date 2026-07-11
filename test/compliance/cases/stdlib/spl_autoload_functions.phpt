--TEST--
stdlib spl_autoload_functions() — callback snapshot parity (#3534, ext/spl/php_spl.c)
--FILE--
<?php
var_export(spl_autoload_functions());
echo "\n";
spl_autoload_register(function (string $class): void {});
spl_autoload_register('spl_autoload');
$funcs = spl_autoload_functions();
var_export($funcs);
echo "\n";
var_export(count($funcs));
echo "\n";
var_export($funcs[0] instanceof Closure);
echo "\n";
var_export($funcs[1]);
echo "\n";
--EXPECT--
array (
)
array (
  0 => \Closure::__set_state(array(
  )),
  1 => 'spl_autoload',
)
2
true
'spl_autoload'
