--TEST--
AOT: substr() with offset and length
--FILE--
<?php
echo substr('hello', 1), "\n";
echo substr('hello', 1, 3), "\n";
--EXPECT--
ello
ell
