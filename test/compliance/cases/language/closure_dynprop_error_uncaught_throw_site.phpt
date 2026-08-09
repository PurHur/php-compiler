--TEST--
Uncaught Closure dynprop Error cites user site, not ExceptionSupport (#29457)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$f = function () {};
$f->a = 1;
--EXPECTF--
PHP Fatal error:  Uncaught Error: Cannot create dynamic property Closure::$a in -:%d
Stack trace:
#0 {main}
  thrown in - on line %d
--EXPECT_EXIT--
255
