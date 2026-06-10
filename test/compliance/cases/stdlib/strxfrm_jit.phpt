--TEST--
JIT: strxfrm() locale transform (#4376, ext/standard/string.c)
--FILE--
<?php
$out = strxfrm('hello');
echo is_string($out) ? "ok\n" : "bad\n";
echo $out, "\n";
$empty = strxfrm('');
echo $empty === '' ? "empty\n" : "bad\n";
--EXPECT--
ok
hello
empty
