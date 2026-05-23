--TEST--
AOT stripslashes()
--FILE--
<?php
echo stripslashes("a\\'b"), "\n";
echo stripslashes(stripslashes("x\\\\y")), "\n";
--EXPECT--
a'b
x\y
