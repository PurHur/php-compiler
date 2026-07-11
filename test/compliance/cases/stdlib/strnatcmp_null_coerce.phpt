--TEST--
stdlib strnatcmp()/strcoll() null operand — coerce to empty string (#11935, ext/standard/string.c)
--FILE--
<?php
echo strnatcmp(null, 'a'), "\n";
echo strcoll(null, 'a'), "\n";
--EXPECT--
-1
-97
