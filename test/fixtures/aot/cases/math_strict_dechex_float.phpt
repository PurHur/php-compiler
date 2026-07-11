--TEST--
AOT dechex() strict call-site float operand (#12273)
--FILE--
<?php
declare(strict_types=1);
dechex(65.9);
--EXPECT--
--EXPECT_EXIT--
255
