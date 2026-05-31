--TEST--
Runtime: restore_exception_handler() restores nested handlers (issue #3146)
--FILE--
<?php
set_exception_handler(fn () => print "a\n");
set_exception_handler(fn () => print "b\n");
restore_exception_handler();
throw new Exception('z');
--EXPECT--
a
--EXPECT_EXIT--
0
