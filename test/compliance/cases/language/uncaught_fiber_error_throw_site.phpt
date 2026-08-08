--TEST--
Uncaught FiberError fatal cites user site and class FiberError, not Error/ExceptionSupport (#28832)
--FILE--
<?php
$f = new Fiber(fn() => 1);
$f->start();
$f->resume();
--EXPECTF--
PHP Fatal error:  Uncaught FiberError: Cannot resume a fiber that is not suspended in -:%d
Stack trace:
#0 -(%d): resume()
#1 {main}
  thrown in - on line %d
--EXPECT_EXIT--
255
