--TEST--
AOT: chr() named codepoint: argument (#23240)
--FILE--
<?php
echo chr(codepoint: 65), "\n";
echo chr(codepoint: 0x42), "\n";
--EXPECT--
A
B
