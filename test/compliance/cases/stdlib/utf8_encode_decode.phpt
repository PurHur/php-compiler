--TEST--
stdlib utf8_encode() / utf8_decode() ISO-8859-1 round-trip
--FILE--
<?php
$latin1 = "\xE9";
echo bin2hex(utf8_encode($latin1)), "\n";
echo bin2hex(utf8_decode(utf8_encode($latin1))), "\n";
echo bin2hex(utf8_encode("\x00\x7F\x80\xFF")), "\n";
echo bin2hex(utf8_decode("\xC3\xA9")), "\n";
echo bin2hex(utf8_decode("\xC3\x28")), "\n";
echo bin2hex(utf8_decode("\xE2\x82\xAC")), "\n";
echo bin2hex(utf8_decode("\xC2\x80")), "\n";
echo (function_exists('utf8_encode') && function_exists('utf8_decode')) ? "exists\n" : "missing\n";
--EXPECT--
c3a9
e9
007fc280c3bf
e9
3f28
3f
80
exists
--EXPECT_EXIT--
0
