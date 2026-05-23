--TEST--
AOT addslashes()
--FILE--
<?php
echo addslashes("a'b"), "\n";
echo addslashes('x"y'), "\n";
--EXPECT--
a\'b
x\"y
