--TEST--
Language: nullsafe ?-> in write context — compile-time fatal (#5323)
--FILE--
<?php
$obj = null;
$obj?->prop = 1;
echo "ran\n";
--EXPECT_EXIT--
255
