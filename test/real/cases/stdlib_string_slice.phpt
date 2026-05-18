--TEST--
Integration: substr, ltrim, rtrim, strrev
--FILE--
<?php
echo substr(ltrim('  abc  '), 0, 3), "\n";
echo strrev(rtrim("  xy  ")), "\n";
--EXPECT--
abc
yx
