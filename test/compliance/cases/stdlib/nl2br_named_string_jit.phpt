--TEST--
stdlib nl2br() named string:/use_xhtml: arguments JIT (#10049, ext/standard/string.c)
--FILE--
<?php
echo nl2br(string: "a\nb"), "\n";
echo nl2br(string: "x\ny", use_xhtml: false), "\n";
--EXPECT--
a<br />
b
x<br>
y
