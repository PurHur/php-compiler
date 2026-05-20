--TEST--
stdlib nl2br() JIT/AOT path
--FILE--
<?php
echo nl2br("a\nb"), "\n";
echo nl2br("line"), "\n";
echo nl2br("x", false), "\n";
echo nl2br("\n"), "\n";
--EXPECT--
a<br />
b
line
x
<br />

