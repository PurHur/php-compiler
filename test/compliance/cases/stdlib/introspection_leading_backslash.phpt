--TEST--
Stdlib: introspection builtins accept leading backslash global names (#12176, basic_functions.c)
--FILE--
<?php
$name = '\\array_map';
echo function_exists($name) ? "fn-ok\n" : "fn-bad\n";
echo class_exists('\\stdClass') ? "class-ok\n" : "class-bad\n";
echo defined('\\PHP_VERSION') ? "const-ok\n" : "const-bad\n";
echo interface_exists('\\Stringable') ? "iface-ok\n" : "iface-bad\n";
echo is_callable('\\strlen') ? "callable-ok\n" : "callable-bad\n";
--EXPECT--
fn-ok
class-ok
const-ok
iface-ok
callable-ok
