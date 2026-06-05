--TEST--
stdlib getmyinode() JIT — ArgumentCountError when extra arguments (#5984)
--FILE--
<?php
getmyinode('extra');
--EXPECT--
--EXPECT_EXIT--
255
