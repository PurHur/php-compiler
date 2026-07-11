--TEST--
AOT intdiv()/dechex() strict call-site float operands (#12275, #12273)
--FILE--
<?php
declare(strict_types=1);
intdiv(10, 3.0);
--EXPECT--
--EXPECT_EXIT--
255
