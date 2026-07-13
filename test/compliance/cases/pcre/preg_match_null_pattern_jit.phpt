--TEST--
stdlib preg_match() JIT null pattern — E_WARNING + false not TypeError (#18681, ext/pcre/php_pcre.c)
--JIT--
--FILE--
<?php
$m = [];
var_dump(preg_match(null, 'x', $m));
?>
--EXPECTF--
PHP Warning:  preg_match(): Empty regular expression in %s on line %d
bool(false)
