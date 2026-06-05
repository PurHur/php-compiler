--TEST--
stdlib getmypid() — ArgumentCountError when extra arguments (#5984)
--FILE--
<?php
getmypid('extra');
--EXPECT--
--EXPECT_EXIT--
255
