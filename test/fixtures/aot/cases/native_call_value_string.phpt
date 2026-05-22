--TEST--
AOT: builtin string arg from boxed __value__ (Native::compileArg, issue #557)
--GET--
route=home
--FILE--
<?php
$route = $_GET['route'];
echo substr($route, 0, 4);
--EXPECT--
home
