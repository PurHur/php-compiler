--TEST--
AOT strxfrm() — libc locale transform (#4376)
--FILE--
<?php
$out = strxfrm('hello');
echo is_string($out) ? "ok\n" : "bad\n";
echo $out, "\n";
--EXPECT--
ok
hello
