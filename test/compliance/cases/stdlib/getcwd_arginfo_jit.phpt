--TEST--
stdlib getcwd() JIT — ArgumentCountError when extra arguments (#5985)
--FILE--
<?php
getcwd('extra');
--EXPECT--
--EXPECT_EXIT--
255
