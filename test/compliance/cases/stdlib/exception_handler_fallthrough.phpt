--TEST--
Runtime: exception handler returning false falls through (issue #3146)
--FILE--
<?php
set_exception_handler(fn () => print "outer\n");
set_exception_handler(fn (): bool => false);
throw new Exception('fall');
--EXPECT--
outer
--EXPECT_EXIT--
0
