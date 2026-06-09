--TEST--
JIT: strcoll() (#4376)
--FILE--
<?php
echo strcoll('abc', 'abc'), "\n";
echo strcoll('abc', 'abd'), "\n";
echo strcoll('abd', 'abc'), "\n";
--EXPECT--
0
-1
1
