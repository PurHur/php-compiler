--TEST--
Language: duplicate named parameter — runtime Error not compile-time (#4299, zend_execute.c)
--FILE--
<?php
function f(int $a, int $b = 0): void {}
f(a: 1, a: 2);
echo "ok\n";
?>
--EXPECTF--
PHP Fatal error:  Uncaught Error: Named parameter $a overwrites previous argument in %s:%d
Stack trace:
#0 {main}
  thrown in %s on line %d
--EXPECT_EXIT--
255
