--TEST--
debug_print_backtrace Reflection return void (#28909, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('debug_print_backtrace');
echo 'has=', $r->hasReturnType() ? '1' : '0', "\n";
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
?>
--EXPECT--
has=1
ret=void
