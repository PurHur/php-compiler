--TEST--
JIT: chmod() missing path emits E_WARNING (#11408)
--FILE--
<?php
$ok = chmod('/nope/phpc-chmod-jit-missing-path', 0644);
echo $ok ? "true\n" : "false\n";
--EXPECT--
PHP Warning:  chmod(): No such file or directory
false
