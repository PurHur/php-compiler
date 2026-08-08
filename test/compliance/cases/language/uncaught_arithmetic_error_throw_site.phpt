--TEST--
Uncaught ArithmeticError fatal cites user site, not ExceptionSupport (#28832)
--FILE--
<?php
echo 1 << -1;
--EXPECTF--
PHP Fatal error:  Uncaught ArithmeticError: Bit shift by negative number in -:%d
Stack trace:
#0 {main}
  thrown in - on line %d
--EXPECT_EXIT--
255
