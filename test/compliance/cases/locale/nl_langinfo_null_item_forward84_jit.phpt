--TEST--
JIT: nl_langinfo(null) — Z_PARAM_LONG coerce to 0, warn, return false on 8.4 forward profile (#19076, ext/standard/nl_langinfo.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$r = @nl_langinfo(null);
echo var_export($r, true), "\n";
--EXPECT--
false
