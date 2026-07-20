--TEST--
AOT: header/printf null soft-null on 8.4 (#21234; preg_quote AOT covered on VM)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$h = null;
$f = null;
header($h); // DEP+coerce; must not TypeError
header('Content-Type: text/plain');
$n = printf($f);
echo (0 === $n) ? "OK\n" : "BAD\n";
--EXPECT--
OK
