--TEST--
AOT: bin2hex() empty and binary strings
--FILE--
<?php
echo bin2hex(''), "\n";
echo bin2hex("\x00\x0f\xff"), "\n";
echo bin2hex('ab'), "\n";
--EXPECT--

000fff
6162
--EXPECT_EXIT--
0
