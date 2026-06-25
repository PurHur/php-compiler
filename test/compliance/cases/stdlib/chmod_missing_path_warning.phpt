--TEST--
stdlib chmod() missing path emits E_WARNING (#11408, ext/standard/filestat.c)
--FILE--
<?php
$ok = chmod('/nope/phpc-chmod-missing-path-warning', 0644);
echo $ok ? "true\n" : "false\n";
--EXPECT--
PHP Warning:  chmod(): No such file or directory
false
