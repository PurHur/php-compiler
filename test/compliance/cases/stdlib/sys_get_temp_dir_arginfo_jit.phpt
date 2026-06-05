--TEST--
stdlib sys_get_temp_dir() JIT — ArgumentCountError when extra arguments (#5984)
--FILE--
<?php
sys_get_temp_dir('extra');
--EXPECT--
--EXPECT_EXIT--
255
