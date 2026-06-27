--TEST--
Stdlib: restore_error_handler()/restore_exception_handler() empty stack return false (#12518, ext/standard/basic_functions.c)
--FILE--
<?php
$r1 = restore_error_handler();
$r2 = restore_error_handler();
echo !$r1 && !$r2 ? "error-ok\n" : "error-bad\n";
$e1 = restore_exception_handler();
$e2 = restore_exception_handler();
echo !$e1 && !$e2 ? "exception-ok\n" : "exception-bad\n";
--EXPECT--
error-ok
exception-ok
