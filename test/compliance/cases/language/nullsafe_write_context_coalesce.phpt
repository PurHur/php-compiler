--TEST--
Language: nullsafe ?-> ??= in write context — compile-time fatal (#5323)
--FILE--
<?php
$a = null;
$a?->b ??= 5;
echo "ran\n";
--EXPECT_EXIT--
255
