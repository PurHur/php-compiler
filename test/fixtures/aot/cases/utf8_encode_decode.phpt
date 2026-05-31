--TEST--
AOT: utf8_encode() / utf8_decode()
--FILE--
<?php
$latin1 = "\xE9";
echo bin2hex(utf8_encode($latin1)), "\n";
echo bin2hex(utf8_decode(utf8_encode($latin1))), "\n";
echo bin2hex(utf8_decode("\xC3\x28")), "\n";
--EXPECT--
c3a9
e9
3f28
--EXPECT_EXIT--
0
