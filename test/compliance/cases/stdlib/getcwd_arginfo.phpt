--TEST--
stdlib getcwd() — ArgumentCountError when extra arguments (#5985)
--FILE--
<?php
getcwd('extra');
--EXPECT--
--EXPECT_EXIT--
255
