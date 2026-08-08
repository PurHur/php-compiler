--TEST--
Uncaught ArgumentCountError fatal cites user site, not ExceptionSupport (#28832)
--FILE--
<?php
strlen('a', 'b');
--EXPECTF--
PHP Fatal error:  Uncaught ArgumentCountError: strlen() expects exactly 1 argument, 2 given in -:%d
Stack trace:
#0 -(%d): strlen()
#1 {main}
  thrown in - on line %d
--EXPECT_EXIT--
255
