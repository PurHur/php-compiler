--TEST--
stdlib gc_enable() JIT — ArgumentCountError when extra arguments (#5985)
--FILE--
<?php
gc_enable('extra');
--EXPECT--
--EXPECT_EXIT--
255
