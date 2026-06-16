--TEST--
stdlib spl_autoload_functions() JIT — callback snapshot parity (#3534, ext/spl/php_spl.c)
--FILE--
<?php
function autoload_marker($c)
{
}
$empty = spl_autoload_functions();
echo count($empty), "\n";
spl_autoload_register(function ($c) {
});
spl_autoload_register('autoload_marker');
$funcs = spl_autoload_functions();
echo count($funcs), "\n";
echo ($funcs[0] instanceof Closure) ? "closure\n" : "not\n";
echo $funcs[1], "\n";
--EXPECT--
0
2
closure
autoload_marker
