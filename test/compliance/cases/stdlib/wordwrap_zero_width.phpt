--TEST--
stdlib wordwrap() zero width — return input unchanged (#10375, ext/standard/string.c)
--FILE--
<?php
echo wordwrap('abc', 0), "\n";
--EXPECT--
abc
