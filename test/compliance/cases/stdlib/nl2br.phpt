--TEST--
stdlib nl2br()
--FILE--
<?php
echo nl2br("a\nb"), "\n";
echo nl2br("line"), "\n";
echo nl2br("x", false), "\n";
--EXPECT--
a<br />
b
line
x
