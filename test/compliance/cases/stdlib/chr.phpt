--TEST--
stdlib chr() single-byte strings
--FILE--
<?php
echo chr(65), "\n";
echo ord(chr(90)), "\n";
echo strlen(chr(0)), "\n";
echo ord(chr(0)), "\n";
echo chr((256 + 65)), "\n";
--EXPECT--
A
90
1
0
A
